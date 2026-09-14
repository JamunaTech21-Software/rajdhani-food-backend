<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Repositories\CustomerRepository;
use Rajdhani\Repositories\LoginAttemptRepository;
use Rajdhani\Repositories\RefreshTokenRepository;

/**
 * Customer sessions (doc §7.1).
 *
 * There is no password anywhere in this class. Customers sign in only with
 * Google, so the credential is an ID token Google signed, and everything after
 * verification is the same session machinery the admin side uses — TokenService
 * is audience-agnostic, so rotation, family revocation and the cookie come for
 * free and cannot drift between the two halves.
 */
final class CustomerAuthService
{
    private const AUDIENCE = 'customer';

    public function __construct(
        private readonly CustomerRepository $customers = new CustomerRepository(),
        private readonly GoogleIdTokenVerifier $google = new GoogleIdTokenVerifier(),
        private readonly RefreshTokenRepository $refreshTokens = new RefreshTokenRepository(),
        private readonly LoginAttemptRepository $attempts = new LoginAttemptRepository(),
        private readonly TokenService $tokens = new TokenService(),
    ) {
    }

    /**
     * @return array{customer:array<string,mixed>,tokens:array{access_token:string,expires_in:int,refresh_expires_at:int},is_new:bool}
     */
    public function signInWithGoogle(string $idToken, Request $request): array
    {
        $profile = $this->google->verify($idToken);

        // Lookup order is load-bearing.
        //
        // 1. google_id — the Google account itself. Definitive, and unaffected
        //    by the person changing their Google address later.
        // 2. email — an account that predates Google linking (doc §7.1 step 5).
        //    Safe to adopt *only* because the verifier already rejected any
        //    token whose email_verified was false; without that check this
        //    branch would let anyone claim an existing account by signing up to
        //    Google with its address.
        // 3. neither — a new customer.
        //
        // Reversing 1 and 2 would hand the account to whoever last used the
        // address rather than to the Google account that owns it.
        $customer = $this->customers->findByGoogleId($profile['sub']);
        $isNew = false;

        if ($customer === null) {
            $customer = $this->customers->findByEmail($profile['email']);

            if ($customer !== null) {
                $this->customers->linkGoogleAccount((string) $customer['id'], $profile['sub']);
            }
        }

        if ($customer === null) {
            $id = $this->customers->create(
                $profile['sub'],
                $profile['email'],
                $profile['name'],
                $profile['picture'],
            );
            $customer = $this->customers->findById($id);
            $isNew = true;

            if ($customer === null) {
                throw ApiError::internal('Could not create the customer account.');
            }
        } else {
            // Name and picture belong to Google; re-copy them each sign-in.
            $this->customers->syncProfileFromGoogle(
                (string) $customer['id'],
                $profile['name'],
                $profile['picture'],
            );
        }

        $customerId = (string) $customer['id'];

        if ((int) $customer['is_blocked'] === 1) {
            $this->attempts->record($profile['email'], self::AUDIENCE, $request->ip, false);

            // Said plainly rather than hidden behind a generic failure: unlike
            // the admin side there is no account to enumerate — the caller has
            // just proved they own this Google account — and a blocked customer
            // retrying forever helps nobody.
            throw ApiError::forbidden('This account has been suspended');
        }

        $this->attempts->record($profile['email'], self::AUDIENCE, $request->ip, true);
        $this->customers->touchLastLogin($customerId);

        $fresh = $this->customers->findById($customerId) ?? $customer;

        return [
            'customer' => $this->publicProfile($fresh),
            'tokens'   => $this->publicTokens($this->tokens->issue(
                self::AUDIENCE,
                $customerId,
                ['email' => (string) $fresh['email']],
                $request,
            )),
            'is_new' => $isNew,
        ];
    }

    /**
     * @return array{customer:array<string,mixed>,tokens:array{access_token:string,expires_in:int,refresh_expires_at:int}}
     */
    public function refresh(Request $request): array
    {
        $consumed = $this->tokens->consumeRefresh(self::AUDIENCE, $request);
        $customerId = (string) $consumed['row']['customer_id'];
        $customer = $this->customers->findById($customerId);

        if ($customer === null || (int) $customer['is_blocked'] === 1) {
            // Blocking takes effect at the next refresh, the same way
            // deactivation does for admins.
            $this->refreshTokens->revokeFamily($consumed['family_id']);

            throw ApiError::unauthenticated('No valid session');
        }

        return [
            'customer' => $this->publicProfile($customer),
            'tokens'   => $this->publicTokens($this->tokens->issue(
                self::AUDIENCE,
                $customerId,
                ['email' => (string) $customer['email']],
                $request,
                familyId: $consumed['family_id'],
            )),
        ];
    }

    public function logout(Request $request): void
    {
        $this->tokens->revokePresented(self::AUDIENCE, $request);
    }

    /** @return array<string,mixed> */
    public function profile(string $customerId): array
    {
        $customer = $this->customers->findById($customerId);

        if ($customer === null) {
            throw ApiError::unauthenticated('No valid session');
        }

        return $this->publicProfile($customer);
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public function updateProfile(string $customerId, array $input): array
    {
        // Doc §9.2 allows phone, city and company — the fields that pre-fill an
        // enquiry form. Not name, avatar or email: Google owns the first two and
        // overwrites them at the next sign-in, and email is a unique identity
        // key here.
        $allowed = ['phone', 'city', 'company_name'];
        $fields = [];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $input)) {
                continue;
            }

            $value = $input[$column];

            if ($value !== null && !is_scalar($value)) {
                throw ApiError::validation("{$column} must be a string", [
                    ['field' => $column, 'message' => 'Expected a string'],
                ]);
            }

            $trimmed = $value === null ? null : trim((string) $value);
            $fields[$column] = $trimmed === '' ? null : $trimmed;
        }

        // isset() already excludes the null that clearing the field produces,
        // so what remains is a string.
        if (isset($fields['phone']) && !$this->looksLikePhone($fields['phone'])) {
            throw ApiError::validation('Enter a valid phone number', [
                ['field' => 'phone', 'message' => 'Enter a valid phone number'],
            ]);
        }

        $this->customers->updateProfile($customerId, $fields);

        return $this->profile($customerId);
    }

    /**
     * Delete the account and everything personal attached to it (doc §9.2).
     *
     * Cascades to wishlist items, reviews and refresh tokens. The reviews are
     * the surprising part — deleting an account removes that person's published
     * reviews from the product pages — but that is what the schema's ON DELETE
     * CASCADE says, and a "delete my data" request that left public content
     * attributed to the customer would not be a deletion.
     */
    public function deleteAccount(string $customerId, Request $request): void
    {
        if ($this->customers->findById($customerId) === null) {
            throw ApiError::unauthenticated('No valid session');
        }

        $this->customers->delete($customerId);

        // The refresh rows went with the cascade; this clears the browser's
        // cookie so the client is not left holding a session-shaped object.
        $this->tokens->revokePresented(self::AUDIENCE, $request);
    }

    private function looksLikePhone(string $value): bool
    {
        // Deliberately loose. Bangladeshi numbers are written with and without
        // +880, with spaces and dashes, and a strict pattern here would reject
        // legitimate input for no security benefit — this field is displayed
        // back to staff, not dialled by machine.
        return preg_match('/^[0-9+\-\s()]{6,32}$/', $value) === 1;
    }

    /**
     * @param array{access_token:string,expires_in:int,refresh_token:string,refresh_expires_at:int} $issued
     *
     * @return array{access_token:string,expires_in:int,refresh_expires_at:int}
     */
    private function publicTokens(array $issued): array
    {
        // The refresh token travels in the HttpOnly cookie only. See
        // AdminAuthService::publicTokens().
        return [
            'access_token'       => $issued['access_token'],
            'expires_in'         => $issued['expires_in'],
            'refresh_expires_at' => $issued['refresh_expires_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function publicProfile(array $row): array
    {
        return [
            'id'    => (string) $row['id'],
            'name'  => (string) $row['name'],
            'email' => (string) $row['email'],

            'avatar_url'   => $row['avatar_url'] === null ? null : (string) $row['avatar_url'],
            'phone'        => $row['phone'] === null ? null : (string) $row['phone'],
            'city'         => $row['city'] === null ? null : (string) $row['city'],
            'company_name' => $row['company_name'] === null ? null : (string) $row['company_name'],

            // google_id is deliberately absent: it identifies the account to
            // Google and the front-end has no use for it.
            'created_at' => (string) $row['created_at'],
        ];
    }
}

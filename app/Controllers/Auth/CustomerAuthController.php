<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Auth;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\CustomerAuthService;

/**
 * `/auth/customer/*` (doc §9.2).
 *
 * As with the admin controller: parse, delegate, respond. The Google token is
 * passed straight through to the service, which is where verification lives —
 * a controller that inspected the token first would be a second place that
 * could get the checks wrong.
 */
final class CustomerAuthController
{
    public function __construct(
        private readonly CustomerAuthService $auth = new CustomerAuthService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function google(Request $request): array
    {
        // Google Identity Services calls it `credential`; most examples call it
        // `id_token`. Both are accepted so the front-end can pass through
        // whatever GIS handed it without a rename.
        $token = $request->input('credential') ?? $request->input('id_token');

        if (!is_string($token) || trim($token) === '') {
            throw ApiError::validation('Google sign-in failed', [
                ['field' => 'credential', 'message' => 'This field is required'],
            ]);
        }

        return $this->auth->signInWithGoogle(trim($token), $request);
    }

    /** @return array<string,mixed> */
    public function refresh(Request $request): array
    {
        return $this->auth->refresh($request);
    }

    /** @return array<string,mixed> */
    public function logout(Request $request): array
    {
        $this->auth->logout($request);

        return ['logged_out' => true];
    }

    /** @return array<string,mixed> */
    public function me(Request $request): array
    {
        return $this->auth->profile($this->customerId($request));
    }

    /** @return array<string,mixed> */
    public function updateMe(Request $request): array
    {
        return $this->auth->updateProfile($this->customerId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function deleteMe(Request $request): array
    {
        $this->auth->deleteAccount($this->customerId($request), $request);

        return ['deleted' => true];
    }

    private function customerId(Request $request): string
    {
        $id = $request->attribute('customer_id');

        if (!is_string($id) || $id === '') {
            throw ApiError::unauthenticated('Authentication required');
        }

        return $id;
    }
}

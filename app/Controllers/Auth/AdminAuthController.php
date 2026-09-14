<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Auth;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\AdminAuthService;

/**
 * `/auth/admin/*` (doc 9.2).
 *
 * Controllers here do three things and nothing else: pull values out of the
 * request, hand them to a service, and return what the service gave back. There
 * is no business rule in this file — no throttle arithmetic, no password policy,
 * no token construction — because a rule written in a controller is a rule that
 * a cron job or a second endpoint cannot reuse (doc 13).
 *
 * Returning an array rather than calling ApiResponse is deliberate: the Kernel
 * wraps whatever comes back in the section 9.1 envelope, so there is exactly
 * one place a success body is shaped.
 */
final class AdminAuthController
{
    public function __construct(
        private readonly AdminAuthService $auth = new AdminAuthService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function login(Request $request): array
    {
        $email = $this->requiredString($request, 'email');
        $password = $this->requiredString($request, 'password');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ApiError::validation('Enter a valid email address', [
                ['field' => 'email', 'message' => 'Enter a valid email address'],
            ]);
        }

        return $this->auth->login($email, $password, $request);
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
        return $this->auth->profile($this->adminId($request));
    }

    /** @return array<string,mixed> */
    public function updateMe(Request $request): array
    {
        return $this->auth->updateProfile($this->adminId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function changePassword(Request $request): array
    {
        $this->auth->changePassword(
            $this->adminId($request),
            $this->requiredString($request, 'current_password'),
            $this->requiredString($request, 'new_password'),
            $request,
        );

        // Every session was just revoked, including this one, so the client has
        // to sign in again. Saying so explicitly stops the dashboard from
        // showing a silent 401 on the next request.
        return ['password_changed' => true, 'reauthentication_required' => true];
    }

    /** @return array<string,mixed> */
    public function forgotPassword(Request $request): array
    {
        $this->auth->forgotPassword($this->requiredString($request, 'email'));

        // Always the same answer, whether or not the address exists. See
        // AdminAuthService::forgotPassword().
        return ['message' => 'If that address belongs to an account, a reset link is on its way'];
    }

    /** @return array<string,mixed> */
    public function resetPassword(Request $request): array
    {
        $this->auth->resetPassword(
            $this->requiredString($request, 'token'),
            $this->requiredString($request, 'password'),
        );

        return ['password_reset' => true];
    }

    /** @return array<string,mixed> */
    public function showInvite(Request $request): array
    {
        return $this->auth->inviteDetails($this->routeToken($request));
    }

    /** @return array<string,mixed> */
    public function acceptInvite(Request $request): array
    {
        return $this->auth->acceptInvite(
            $this->routeToken($request),
            $this->requiredString($request, 'password'),
            $request,
        );
    }

    private function adminId(Request $request): string
    {
        // Set by RequireAdmin. Absent means the route was registered without
        // that middleware, which is a wiring bug rather than a client error.
        $id = $request->attribute('admin_id');

        if (!is_string($id) || $id === '') {
            throw ApiError::unauthenticated('Authentication required');
        }

        return $id;
    }

    private function routeToken(Request $request): string
    {
        $token = $request->attribute('token');

        if (!is_string($token) || $token === '') {
            throw ApiError::notFound('This invitation is not valid or has already been used');
        }

        return $token;
    }

    private function requiredString(Request $request, string $field): string
    {
        $value = $request->input($field);

        if (!is_string($value) || trim($value) === '') {
            throw ApiError::validation('Some required fields are missing', [
                ['field' => $field, 'message' => 'This field is required'],
            ]);
        }

        // Passwords are never trimmed — a trailing space is part of the secret
        // and silently removing it makes a working password stop working the
        // moment the client changes.
        return str_contains($field, 'password') ? $value : trim($value);
    }
}

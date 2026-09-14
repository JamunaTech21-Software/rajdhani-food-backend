<?php

declare(strict_types=1);

/**
 * /auth/* — section 9.2. Two audiences, one namespace.
 *
 * Which routes carry RequireAdmin is the security-relevant part of this file.
 * Login, refresh, forgot-password, reset-password and the invite pair are public
 * by necessity: the caller has no access token yet, which is the entire point of
 * asking. Each of those instead proves possession of something — a password, a
 * refresh cookie, an emailed single-use token — inside the service.
 *
 * Logout is public for the same reason and one more: a session whose access
 * token has already expired must still be able to end itself, or a user who
 * leaves a tab open overnight cannot log out.
 */

use Rajdhani\Controllers\Auth\AdminAuthController;
use Rajdhani\Controllers\Auth\CustomerAuthController;
use Rajdhani\Http\Router;
use Rajdhani\Middleware\RequireAdmin;
use Rajdhani\Middleware\RequireCustomer;

/** @var Router $router */

$router->group('/auth', [], static function (Router $r): void {
    // RTPP-12 — customer (Google Identity Services). No password flow exists
    // for customers, and no self-registration endpoint either: the account is
    // created by the first successful Google sign-in.
    $r->post('/customer/google', Router::to(CustomerAuthController::class, 'google'));
    $r->post('/customer/refresh', Router::to(CustomerAuthController::class, 'refresh'));
    $r->post('/customer/logout', Router::to(CustomerAuthController::class, 'logout'));

    $r->get('/customer/me', Router::to(CustomerAuthController::class, 'me'), [RequireCustomer::class]);
    $r->patch('/customer/me', Router::to(CustomerAuthController::class, 'updateMe'), [RequireCustomer::class]);
    $r->delete('/customer/me', Router::to(CustomerAuthController::class, 'deleteMe'), [RequireCustomer::class]);

    // RTPP-11 — admin
    $r->post('/admin/login', Router::to(AdminAuthController::class, 'login'));
    $r->post('/admin/refresh', Router::to(AdminAuthController::class, 'refresh'));
    $r->post('/admin/logout', Router::to(AdminAuthController::class, 'logout'));

    $r->get('/admin/me', Router::to(AdminAuthController::class, 'me'), [RequireAdmin::class]);
    $r->patch('/admin/me', Router::to(AdminAuthController::class, 'updateMe'), [RequireAdmin::class]);
    $r->post(
        '/admin/change-password',
        Router::to(AdminAuthController::class, 'changePassword'),
        [RequireAdmin::class],
    );

    $r->post('/admin/forgot-password', Router::to(AdminAuthController::class, 'forgotPassword'));
    $r->post('/admin/reset-password', Router::to(AdminAuthController::class, 'resetPassword'));

    // The token is in the path rather than the body because the invitee arrives
    // by clicking a link; the GET lets the set-password screen check the token
    // before asking for a password it might then have to throw away.
    $r->get('/admin/invite/:token', Router::to(AdminAuthController::class, 'showInvite'));
    $r->post('/admin/invite/:token/accept', Router::to(AdminAuthController::class, 'acceptInvite'));
});

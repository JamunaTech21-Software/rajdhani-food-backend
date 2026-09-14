<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\RecaptchaVerifier;

/**
 * The two anti-spam checks doc §14.2 asks for on every public form
 * (enquiry, dealer application, contact, newsletter, review — RTPP-34):
 * a honeypot field, checked here before reCAPTCHA even runs, and a
 * reCAPTCHA v3 token, verified by `RecaptchaVerifier`.
 *
 *     $r->post('/enquiries', …, [new ThrottleForm('enquiry'), new VerifyHuman('enquiry')]);
 *
 * `$action` doubles as both the honeypot/token check's identity and the
 * reCAPTCHA v3 action name Google's own dashboard groups by — one string,
 * not two, the same way `ThrottleForm`'s form name is one string used for
 * both its counter key and its log context.
 *
 * Only a submission counts, the same exemption `ThrottleForm` already
 * makes: a `GET` of the form's own page or a preflight carries no token to
 * check and must not be rejected for lacking one.
 */
final class VerifyHuman implements Middleware
{
    /** A field name no real field on any of the five forms uses — a bot's autofill is the only thing that ever populates it. */
    private const HONEYPOT_FIELD = 'website';

    public function __construct(
        private readonly string $action,
        private readonly RecaptchaVerifier $recaptcha = new RecaptchaVerifier(),
    ) {
    }

    public function handle(Request $request, callable $next): mixed
    {
        if ($request->method === 'OPTIONS' || $request->method === 'GET') {
            return $next($request);
        }

        $honeypot = $request->body[self::HONEYPOT_FIELD] ?? null;

        if (is_string($honeypot) && trim($honeypot) !== '') {
            // Never revealed as a distinct reason — see RecaptchaVerifier's
            // class doc on why every rejection looks identical.
            throw ApiError::forbidden('We could not verify this submission. Please try again.');
        }

        $token = $request->body['recaptcha_token'] ?? null;
        $this->recaptcha->verify(is_string($token) ? $token : '', $this->action, $request->ip);

        return $next($request);
    }
}

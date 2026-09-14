<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Http\Request;
use Rajdhani\Services\RateLimiter;

/**
 * The public-form limit from §14.2: 5 submissions per hour per IP.
 *
 * Applied per route rather than globally, because it is far tighter than the
 * global limit and only makes sense on the four public forms — product
 * enquiries, dealer applications, contact messages and newsletter sign-up
 * (§10). Configured with the form's name so the four have separate budgets:
 * someone who has just submitted an enquiry can still report a problem through
 * the contact form.
 *
 *     $r->post('/enquiries', …, [new ThrottleForm('enquiry')]);
 *
 * This is one of three protections on those forms. reCAPTCHA v3 and the
 * honeypot field are the other two and are separate work — a rate limit alone
 * stops volume, not a determined single submission.
 */
final class ThrottleForm implements Middleware
{
    public function __construct(
        private readonly string $form,
        private readonly RateLimiter $limiter = new RateLimiter(),
    ) {
    }

    public function handle(Request $request, callable $next): mixed
    {
        // Only the submission counts. A GET of the form's own page, or a
        // preflight, must not consume the visitor's five.
        if ($request->method === 'OPTIONS' || $request->method === 'GET') {
            return $next($request);
        }

        $this->limiter->enforce('public_form', $request->ip, $this->form);

        return $next($request);
    }
}

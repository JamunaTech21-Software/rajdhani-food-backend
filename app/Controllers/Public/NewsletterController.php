<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\NewsletterService;

/**
 * `/public/newsletter` (doc §9.6, RTPP-31). `subscribe` is rate-limited
 * (`ThrottleForm('newsletter')`) — wired in `routes/public.php`. `unsubscribe`
 * is a `GET` and deliberately not rate-limited: `ThrottleForm` itself skips
 * `GET` (only a submission counts against the five-per-hour budget), and a
 * one-click unsubscribe link is not a form a visitor "submits" repeatedly.
 */
final class NewsletterController
{
    public function __construct(
        private readonly NewsletterService $newsletter = new NewsletterService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function subscribe(Request $request): array
    {
        return $this->newsletter->subscribe($request->body);
    }

    /** @return array<string,mixed> */
    public function unsubscribe(Request $request): array
    {
        $token = $request->attribute('token');

        if (!is_string($token) || $token === '') {
            throw ApiError::notFound('No such subscription');
        }

        return $this->newsletter->unsubscribe($token);
    }
}

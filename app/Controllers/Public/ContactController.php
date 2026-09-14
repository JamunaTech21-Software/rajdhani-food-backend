<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\ContactService;

/**
 * `POST /public/contact` (doc §9.6, RTPP-31). Rate-limited
 * (`ThrottleForm('contact')`) — wired in `routes/public.php`.
 */
final class ContactController
{
    public function __construct(
        private readonly ContactService $contact = new ContactService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->contact->submit($request->ip, $request->body);
    }
}

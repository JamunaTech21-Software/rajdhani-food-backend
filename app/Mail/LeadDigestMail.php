<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/** The weekly `Jobs\LeadDigest` summary (doc §13; RTPP-33) → the sales list. */
final class LeadDigestMail extends Mailable
{
    public function __construct(
        private readonly int $enquiries,
        private readonly int $applications,
        private readonly int $messages,
        private readonly int $subscribers,
    ) {
    }

    public function subject(): string
    {
        return 'Weekly lead summary';
    }

    public function html(): string
    {
        return $this->wrap('This week at a glance', $this->table([
            ['New product enquiries', (string) $this->enquiries],
            ['New dealer applications', (string) $this->applications],
            ['New contact messages', (string) $this->messages],
            ['New newsletter subscribers', (string) $this->subscribers],
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/** "Dealer application submitted" → the sales list (doc §14.4; RTPP-33). */
final class DealerApplicationMail extends Mailable
{
    public function __construct(
        private readonly string $applicationId,
        private readonly string $fullName,
        private readonly string $companyName,
        private readonly string $phone,
        private readonly string $email,
        private readonly string $districtName,
    ) {
    }

    public function subject(): string
    {
        return "New dealer application — {$this->applicationId}";
    }

    public function html(): string
    {
        return $this->wrap('New dealer application', $this->table([
            ['Application ID', $this->applicationId],
            ['Applicant', $this->fullName],
            ['Company', $this->companyName],
            ['District', $this->districtName],
            ['Phone', $this->phone],
            ['Email', $this->email],
        ]));
    }
}

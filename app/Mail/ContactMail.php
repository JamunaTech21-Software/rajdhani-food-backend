<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/** "Contact message received" → the contact list (doc §14.4; RTPP-33). */
final class ContactMail extends Mailable
{
    public function __construct(
        private readonly string $name,
        private readonly string $email,
        private readonly ?string $phone,
        private readonly ?string $subject,
        private readonly string $message,
    ) {
    }

    public function subject(): string
    {
        return $this->subject !== null && $this->subject !== ''
            ? "New contact message: {$this->subject}"
            : 'New contact message';
    }

    public function html(): string
    {
        return $this->wrap('New contact message', $this->table([
            ['Name', $this->name],
            ['Email', $this->email],
            ['Phone', $this->phone],
            ['Subject', $this->subject],
            ['Message', $this->message],
        ]));
    }
}

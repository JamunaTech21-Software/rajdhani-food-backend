<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/** "Product enquiry submitted" → the sales list (doc §14.4; RTPP-33). */
final class EnquiryMail extends Mailable
{
    public function __construct(
        private readonly string $referenceNo,
        private readonly string $name,
        private readonly string $email,
        private readonly string $phone,
        private readonly ?string $productName,
        private readonly string $message,
    ) {
    }

    public function subject(): string
    {
        return "New product enquiry — {$this->referenceNo}";
    }

    public function html(): string
    {
        return $this->wrap('New product enquiry', $this->table([
            ['Reference', $this->referenceNo],
            ['Product', $this->productName ?? 'General enquiry'],
            ['Name', $this->name],
            ['Phone', $this->phone],
            ['Email', $this->email],
            ['Message', $this->message],
        ]));
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/** "Review approved" → confirmation to the reviewer (doc §14.4; RTPP-33). */
final class ReviewApprovedMail extends Mailable
{
    public function __construct(
        private readonly string $productName,
    ) {
    }

    public function subject(): string
    {
        return "Your review of {$this->productName} is now live";
    }

    public function html(): string
    {
        return $this->wrap('Your review was approved', sprintf(
            '<p>%s</p>',
            $this->escape("Thanks for your feedback — your review of {$this->productName} has been approved and is now visible on the product page."),
        ));
    }
}

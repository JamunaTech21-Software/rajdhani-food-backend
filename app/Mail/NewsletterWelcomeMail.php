<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/**
 * "Newsletter subscription" → welcome email to the subscriber themselves
 * (doc §14.4; RTPP-33) — the one event in the matrix whose recipient is the
 * visitor, not an admin-configured list.
 *
 * Carries a real, working unsubscribe link — the same `GET
 * /public/newsletter/unsubscribe/{token}` a one-click link always resolves,
 * not a front-end page this backend ticket has no scope to design (the
 * front-end is a separate developer's scope, doc §19 deviation 7).
 */
final class NewsletterWelcomeMail extends Mailable
{
    public function __construct(
        private readonly string $siteName,
        private readonly string $unsubscribeUrl,
    ) {
    }

    public function subject(): string
    {
        return "Welcome to the {$this->siteName} newsletter";
    }

    public function html(): string
    {
        return $this->wrap('Thanks for subscribing', sprintf(
            '<p>%s</p><p><a href="%s">Unsubscribe</a></p>',
            $this->escape("You're now subscribed to updates from {$this->siteName}."),
            $this->escape($this->unsubscribeUrl),
        ));
    }
}

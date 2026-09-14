<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/**
 * "Review submitted" → the Editor list (doc §14.4; RTPP-33). Recipients
 * are resolved from live `admin_users` holding the Editor role
 * (`AdminUserRepository::activeEmailsForRole()`), not a `settings` key —
 * see that method's own doc for why.
 *
 * The doc's content spec is "product and excerpt, link to moderation
 * queue" — this class carries the first two. The link is deliberately
 * omitted: this repo has no confirmed admin-dashboard base URL to build one
 * from (`CORS_ORIGINS` holds every allowed origin, customer site included,
 * with no way to tell which one is the admin dashboard), and the front-end
 * is a separate developer's scope (doc §19 deviation 7) — a guessed URL
 * that turns out wrong is worse than no link at all.
 */
final class ReviewSubmittedMail extends Mailable
{
    public function __construct(
        private readonly string $productName,
        private readonly int $rating,
        private readonly string $excerpt,
    ) {
    }

    public function subject(): string
    {
        return "New review awaiting moderation — {$this->productName}";
    }

    public function html(): string
    {
        return $this->wrap('New review submitted', $this->table([
            ['Product', $this->productName],
            ['Rating', str_repeat('★', $this->rating) . str_repeat('☆', 5 - $this->rating)],
            ['Excerpt', $this->excerpt],
        ]));
    }
}

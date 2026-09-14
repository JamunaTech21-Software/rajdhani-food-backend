<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/**
 * "Admin invited" → an action link (doc §14.4; RTPP-33).
 *
 * **Built, not yet wired to a live trigger.** Admin user creation — a Super
 * Admin issuing a fresh `invite_token` from the dashboard — is doc §9.11's
 * "Admin list, invite flow, role editing, deactivate", and nothing in this
 * codebase creates an `admin_users` row today: `AdminUserRepository`'s own
 * class doc is explicit that it has no `create()`, and there is no
 * `AdminUserService`/`AdminUserController` yet. That admin-user-management
 * feature is not in Phase 2's 19-work-package list (`plan.md` §6) — this
 * class exists so whichever future ticket builds it has a mailer ready to
 * call, rather than needing this ticket reopened.
 */
final class AdminInviteMail extends Mailable
{
    public function __construct(
        private readonly string $inviteeName,
        private readonly string $acceptUrl,
    ) {
    }

    public function subject(): string
    {
        return 'You have been invited to the Rajdhani admin dashboard';
    }

    public function html(): string
    {
        return $this->wrap('You are invited', sprintf(
            '<p>%s</p><p><a href="%s">Accept invitation</a></p>',
            $this->escape("Hi {$this->inviteeName}, you've been invited to join the Rajdhani admin dashboard. Follow the link below to set your password."),
            $this->escape($this->acceptUrl),
        ));
    }
}

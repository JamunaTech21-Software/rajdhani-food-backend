<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/**
 * "Password reset" → an action link (doc §14.4; RTPP-33). Wired into
 * `AdminAuthService::forgotPassword()`, which already generated the reset
 * token and logged it — this is the "mail delivery wired up with the
 * notification work" that method's own pre-existing comment named.
 */
final class AdminPasswordResetMail extends Mailable
{
    public function __construct(
        private readonly string $resetUrl,
        private readonly int $ttlHours,
    ) {
    }

    public function subject(): string
    {
        return 'Reset your Rajdhani admin password';
    }

    public function html(): string
    {
        return $this->wrap('Password reset requested', sprintf(
            '<p>%s</p><p><a href="%s">Reset password</a></p>',
            $this->escape("A password reset was requested for your admin account. This link expires in {$this->ttlHours} hour(s). If you didn't request this, you can ignore this email."),
            $this->escape($this->resetUrl),
        ));
    }
}

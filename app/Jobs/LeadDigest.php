<?php

declare(strict_types=1);

namespace Rajdhani\Jobs;

use Rajdhani\Mail\LeadDigestMail;
use Rajdhani\Repositories\LeadDigestRepository;
use Rajdhani\Support\Mailer;
use Rajdhani\Support\NotificationRecipients;

/**
 * Weekly summary to the sales list (doc §13 `app/Jobs/`, §14.4; RTPP-33).
 * Invoked by cPanel cron, not by a daemon — there is no long-lived process
 * on shared hosting for this to run inside of (§5.4) — via `bin/lead-digest.php`.
 *
 * **The recipient list is `enquiry_notify_emails`**, not a new dedicated
 * key: §14.4's own row for "Product enquiry submitted" already labels that
 * setting "Sales list", and this digest is a summary for the same
 * audience, not a second one that would need its own separately
 * maintained address list to drift from the first.
 *
 * The four other cron jobs `app/Jobs/` doc §13 lists — `TokenCleanup`,
 * `MediaCleanup`, `SitemapBuild` — are not this ticket's scope: only
 * `LeadDigest` is named in this ticket's own "Scope" bullets.
 */
final class LeadDigest
{
    public function __construct(
        private readonly LeadDigestRepository $counts = new LeadDigestRepository(),
        private readonly Mailer $mailer = new Mailer(),
        private readonly NotificationRecipients $recipients = new NotificationRecipients(),
    ) {
    }

    public function run(): bool
    {
        $counts = $this->counts->weeklyCounts();

        $mail = new LeadDigestMail(
            $counts['enquiries'],
            $counts['applications'],
            $counts['messages'],
            $counts['subscribers'],
        );

        return $this->mailer->send($this->recipients->forSetting('enquiry_notify_emails'), $mail->subject(), $mail->html());
    }
}

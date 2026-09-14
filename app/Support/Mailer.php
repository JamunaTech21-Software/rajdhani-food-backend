<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use PHPMailer\PHPMailer\PHPMailer;
use Rajdhani\Kernel;
use Throwable;

/**
 * The one place PHPMailer is touched (doc §13, §14.4; RTPP-33).
 *
 * **There is no queue worker on shared hosting (§5.4)**: every send happens
 * inline, after whatever it is confirming has already been committed —
 * every caller in this codebase follows "commit first, mail second" — with
 * a short SMTP timeout (`config('mail.timeout_seconds')`, 5s) and every
 * failure caught *here* rather than left to bubble. `send()` therefore
 * never throws; it returns whether the message actually left, and always
 * logs enough context (recipients, subject, elapsed time, and — on failure
 * — the SMTP error) for someone to retry manually, per the ticket's own
 * title: "inline send with logged retry".
 *
 * **Every call logs its elapsed time, success or failure** — this is the
 * number the ticket's DoD asks to have "measured and recorded on this
 * issue": the added latency inline sending puts on a form submission.
 *
 * **An unconfigured `MAIL_HOST` is a no-op, not an error.** This codebase's
 * own `.env` has no real SMTP credentials yet — the client's Gmail App
 * Password is a still-open credential dependency (doc §19 item 7) — so
 * every environment without one (including this test suite) must behave
 * correctly with mail simply not sending, exactly the state
 * `AdminAuthService::forgotPassword()`'s own pre-existing comment already
 * anticipated ("SMTP is not configured yet").
 */
final class Mailer
{
    /**
     * @param list<string> $to
     */
    public function send(array $to, string $subject, string $htmlBody, ?string $replyTo = null): bool
    {
        $recipients = array_values(array_filter($to, static fn (string $email): bool => $email !== ''));

        if ($recipients === []) {
            // Not a failure — an empty notify-list is a valid admin
            // configuration (doc §19 item 4: "stay empty by default").
            return false;
        }

        $host = config('mail.host');

        if (!is_string($host) || $host === '') {
            Kernel::logger()->info('Mail skipped — SMTP not configured', [
                'to' => $recipients, 'subject' => $subject,
            ]);

            return false;
        }

        $startedAt = microtime(true);

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) config('mail.port', 587);
            $mail->SMTPAuth = true;
            $mail->Username = (string) config('mail.username');
            $mail->Password = (string) config('mail.password');
            $mail->SMTPSecure = (string) config('mail.encryption', 'tls');

            // PHPMailer only honours this as the *connection* timeout when
            // SMTPKeepAlive is false, which is the default — kept explicit
            // here because a silent regression would turn this codebase's
            // deliberate 5-second worst case into PHP's much longer default
            // socket timeout, exactly the failure mode this class exists to
            // prevent.
            $mail->Timeout = (int) config('mail.timeout_seconds', 5);

            $mail->setFrom((string) config('mail.from.address'), (string) config('mail.from.name', ''));

            foreach ($recipients as $recipient) {
                $mail->addAddress($recipient);
            }

            if ($replyTo !== null && $replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = trim(strip_tags($htmlBody));

            $mail->send();

            Kernel::logger()->info('Mail sent', [
                'to' => $recipients, 'subject' => $subject, 'elapsed_ms' => $this->elapsedMs($startedAt),
            ]);

            return true;
        } catch (Throwable $e) {
            Kernel::logger()->error('Mail send failed', [
                'to' => $recipients, 'subject' => $subject, 'elapsed_ms' => $this->elapsedMs($startedAt),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}

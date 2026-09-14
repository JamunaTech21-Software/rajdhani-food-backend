<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Support\Env;
use Rajdhani\Support\Mailer;

/**
 * `Mailer::send()` (doc §13, §14.4; RTPP-33) — the paths that don't need a
 * real network call. The "kill the SMTP connection mid-send and measure the
 * latency" scenario from the DoD is deliberately *not* a test here: `config()`
 * caches each file's values in a `static` array for the life of the PHP
 * process (`app/Support/functions.php`), so overriding `MAIL_HOST` after any
 * earlier test has already read `config('mail.*')` would leak into every
 * later test in the same run. That scenario is verified live against the
 * running dev server instead — see the Jira comment for the measured numbers.
 */
final class MailerTest extends TestCase
{
    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);
    }

    /**
     * This repo's own `.env` has no `MAIL_HOST` yet — the client's Gmail App
     * Password is a still-open credential dependency (doc §19 item 7) — so
     * this is the actual state every environment without one is in, not a
     * simulated one.
     */
    public function testSendingWithNoConfiguredHostIsANoOpNotAnError(): void
    {
        $mailer = new Mailer();

        $result = $mailer->send(['someone@example.test'], 'Subject', '<p>Body</p>');

        self::assertFalse($result);
    }

    public function testSendingToNoRecipientsIsANoOpNotAnError(): void
    {
        $mailer = new Mailer();

        $result = $mailer->send([], 'Subject', '<p>Body</p>');

        self::assertFalse($result);
    }

    public function testBlankRecipientsAreFilteredOut(): void
    {
        $mailer = new Mailer();

        // Two blanks and nothing real — same as an empty list once filtered.
        $result = $mailer->send(['', ''], 'Subject', '<p>Body</p>');

        self::assertFalse($result);
    }
}

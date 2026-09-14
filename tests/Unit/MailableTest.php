<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Mail\ContactMail;
use Rajdhani\Mail\EnquiryMail;

/**
 * `Mailable`'s template shaping (doc §13, §14.4; RTPP-33) — pure string
 * building, testable with no database or network in reach. The one thing
 * genuinely worth proving: every piece of visitor-submitted content in a
 * notification email is HTML-escaped, since these bodies are built by
 * string interpolation rather than a templating engine that escapes by
 * default.
 */
final class MailableTest extends TestCase
{
    public function testEnquiryMailEscapesUserSubmittedContent(): void
    {
        $mail = new EnquiryMail(
            'RDFP-ENQ-2026-00001',
            '<script>alert(1)</script>',
            'visitor@example.test',
            '+8801700000000',
            'Green Tea',
            'Interested in bulk pricing',
        );

        $html = $mail->html();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEnquiryMailWithNoProductSaysGeneralEnquiry(): void
    {
        $mail = new EnquiryMail('RDFP-ENQ-2026-00001', 'Visitor', 'visitor@example.test', '+8801700000000', null, 'Hello');

        self::assertStringContainsString('General enquiry', $mail->html());
    }

    public function testContactMailSubjectFallsBackWhenNoSubjectGiven(): void
    {
        $withSubject = new ContactMail('Visitor', 'visitor@example.test', null, 'Bulk order', 'Message');
        $withoutSubject = new ContactMail('Visitor', 'visitor@example.test', null, null, 'Message');

        self::assertStringContainsString('Bulk order', $withSubject->subject());
        self::assertSame('New contact message', $withoutSubject->subject());
    }
}

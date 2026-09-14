<?php

declare(strict_types=1);

namespace Rajdhani\Mail;

/**
 * Base for §14.4's eight notification-matrix emails (doc §13, RTPP-33).
 *
 * One class per event — each knows only how to shape its own subject and
 * body from the data it was given; none of them knows how to send, or
 * where its recipients come from (that's `NotificationRecipients` and
 * whichever role admin_users query applies), so a template can be unit
 * tested with plain strings and no database or network in reach.
 */
abstract class Mailable
{
    abstract public function subject(): string;

    abstract public function html(): string;

    /** Escapes for interpolation into the HTML body — every piece of user-submitted content in a template goes through this. */
    protected function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    /** A minimal, consistent wrapper so eight templates don't each reinvent one. */
    protected function wrap(string $title, string $innerHtml): string
    {
        $title = $this->escape($title);

        return <<<HTML
            <!doctype html>
            <html>
              <body style="font-family: sans-serif; color: #1a1a1a;">
                <h2>{$title}</h2>
                {$innerHtml}
              </body>
            </html>
            HTML;
    }

    /**
     * A `<table>` of label/value rows — the shape every lead-notification
     * email in this ticket shares.
     *
     * @param list<array{0:string,1:?string}> $rows
     */
    protected function table(array $rows): string
    {
        $cells = '';

        foreach ($rows as [$label, $value]) {
            $cells .= sprintf(
                '<tr><td style="padding:4px 12px 4px 0;"><strong>%s</strong></td><td>%s</td></tr>',
                $this->escape($label),
                nl2br($this->escape($value)),
            );
        }

        return "<table>{$cells}</table>";
    }
}

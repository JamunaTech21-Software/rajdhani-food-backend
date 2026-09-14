<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side rich text sanitisation (doc §14.2, RTPP-19).
 *
 * Every product tab — description, ingredients, nutrition, brewing guide,
 * packaging — is HTML typed by an editor and rendered, unescaped, on the public
 * site. Storing it as given would make the product form a stored-XSS vector:
 * `<img src=x onerror=...>` in a "Brewing Guide" field runs in every visitor's
 * browser the moment the product is published. This is the one place in the
 * request lifecycle where that stops being possible — sanitised **before
 * storage**, so nothing downstream (the public API, a future export, an admin
 * viewing another admin's draft) can see what was typed, only what survived.
 *
 * The allowlist is deliberately small: the tags a rich text editor's basic
 * toolbar produces, and nothing that can carry behaviour. No `<script>`,
 * `<iframe>`, `<style>`, `<object>`, no `on*` attributes, no `style` attribute
 * (which is itself a script vector via `expression()` in older engines and a
 * layout-breaking one in every engine). No inline images — product photos go
 * through the media library (RTPP-21), not pasted into a text field.
 *
 * HTMLPurifier keeps a serialized copy of its parsed definition in
 * `storage/cache/htmlpurifier/` so it does not rebuild the allowlist on every
 * request; the directory is created on first use if missing.
 */
final class RichText
{
    private static ?HTMLPurifier $purifier = null;

    /**
     * @return string|null null for empty or whitespace-only input, so a tab
     *                      with nothing typed into it stores NULL rather than
     *                      an empty string — the two must not be conflated,
     *                      since the API contract for every tab field is "null
     *                      means hide this tab, don't render it blank" (§10.2)
     */
    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = trim(self::purifier()->purify($html));

        return $clean === '' ? null : $clean;
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $cacheDir = base_path('storage/cache/htmlpurifier');

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Doctype', 'HTML 4.01 Transitional');

        // A rich text editor's basic toolbar and nothing more. In particular:
        // no style attribute, no class/id (nothing here should be a script or
        // layout hook), no images (RTPP-21 owns product imagery).
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br',
            'strong', 'b', 'em', 'i', 'u',
            'ul', 'ol', 'li',
            'a[href|title]',
            'h3', 'h4',
            'blockquote',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
        ]));

        // Only http(s) — a javascript: or data: URI in an href is exactly the
        // kind of thing an allowlist on tags alone would miss.
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true]);
        $config->set('HTML.TargetBlank', true);

        // A cache HTMLPurifier fully controls the contents of, isolated from
        // every other use of storage/cache/ in this project.
        if (is_dir($cacheDir) && is_writable($cacheDir)) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            // Shared hosting can end up with a read-only storage/ (RTPP-90 is
            // still confirming write access). Purifying without a cache is
            // slower per request, not broken — degrade, do not fail the save.
            $config->set('Cache.DefinitionImpl', null);
        }

        return self::$purifier = new HTMLPurifier($config);
    }
}

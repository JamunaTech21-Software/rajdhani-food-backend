<?php

declare(strict_types=1);

namespace Rajdhani\Helpers;

/**
 * URL slugs for products, categories, news and gallery categories.
 *
 * Slugs are globally unique in this schema (doc 6.2) — no composite key — so
 * uniqueness is settled by the database, and this helper only produces the
 * candidate. Callers pass an existence check to `unique()`.
 */
final class SlugHelper
{
    public static function make(string $value): string
    {
        $slug = mb_strtolower(trim($value), 'UTF-8');

        // Transliterate what can be transliterated; drop what cannot. Product
        // names are English (doc 19: English only), but copy pasted from a
        // designer's file routinely carries curly quotes and en-dashes.
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);

        if ($transliterated !== false) {
            $slug = $transliterated;
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        // VARCHAR(191) in the schema; leave room for a numeric suffix.
        if (strlen($slug) > 180) {
            $slug = rtrim(substr($slug, 0, 180), '-');
        }

        return $slug === '' ? 'item' : $slug;
    }

    /**
     * Append -2, -3, … until the candidate is free.
     *
     * @param callable(string): bool $exists returns true when the slug is taken
     */
    public static function unique(string $value, callable $exists): string
    {
        $base = self::make($value);

        if (!$exists($base)) {
            return $base;
        }

        for ($suffix = 2; $suffix < 1000; $suffix++) {
            $candidate = "{$base}-{$suffix}";

            if (!$exists($candidate)) {
                return $candidate;
            }
        }

        // Pathological case: fall back to something guaranteed free rather than
        // looping forever.
        return $base . '-' . strtolower(substr(UlidHelper::generate(), -6));
    }
}

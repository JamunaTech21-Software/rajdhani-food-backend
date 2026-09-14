<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

/**
 * `site_profile` — the singleton that replaced the `brands` table (doc §6, §8.2).
 *
 * There is exactly one row and its primary key is pinned to 1 by
 * `CHECK (id = 1)`. **Nothing here inserts.** The row is created once by
 * SiteProfileSeeder; every later change is an UPDATE of row 1, so the constraint
 * is never the thing that has to catch a mistake — it is the backstop for a
 * mistake this class does not make.
 *
 * The read joins `media_assets` four times because the front-end needs URLs, not
 * ids. All four are LEFT JOINs: a site with no dark logo is a normal site, and
 * an INNER JOIN would silently return no profile at all.
 */
final class SiteProfileRepository extends Repository
{
    /**
     * The profile with its images resolved.
     *
     * @return array<string,mixed>|null null only before the seeder has run
     */
    public function find(): ?array
    {
        return $this->one(
            'SELECT
                 p.*,
                 light.secure_url   AS logo_light_url,
                 light.alt_text     AS logo_light_alt,
                 dark.secure_url    AS logo_dark_url,
                 dark.alt_text      AS logo_dark_alt,
                 favicon.secure_url AS favicon_url,
                 og.secure_url      AS og_image_url
               FROM site_profile p
               LEFT JOIN media_assets light   ON light.id   = p.logo_light_id
               LEFT JOIN media_assets dark    ON dark.id    = p.logo_dark_id
               LEFT JOIN media_assets favicon ON favicon.id = p.favicon_id
               LEFT JOIN media_assets og      ON og.id      = p.og_image_id
              WHERE p.id = 1
              LIMIT 1'
        );
    }

    /**
     * Update the singleton.
     *
     * @param array<string,scalar|null> $fields already allowlisted by the service
     *
     * @return int rows changed; 0 means the values were already what was sent
     */
    public function update(array $fields): int
    {
        if ($fields === []) {
            return 0;
        }

        $assignments = [];
        $parameters = [':updated_at' => $this->now()];

        foreach ($fields as $column => $value) {
            $assignments[] = "`{$column}` = :{$column}";
            $parameters[":{$column}"] = $value;
        }

        // `WHERE id = 1` rather than an unqualified UPDATE: the table holds one
        // row today, and an unqualified statement is the kind that stays
        // correct right up until it is not.
        return $this->run(
            'UPDATE site_profile SET ' . implode(', ', $assignments) . ', updated_at = :updated_at WHERE id = 1',
            $parameters,
        );
    }
}

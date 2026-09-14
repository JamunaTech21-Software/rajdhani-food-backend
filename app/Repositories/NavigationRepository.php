<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

/**
 * `menu_links` and `social_links` (doc §8.2) — the navigation half of
 * `GET /public/layout`.
 *
 * Both queries filter on `is_active` and order by `sort_order`, which is what
 * the covering indexes `ix_menu_links_location` and `ix_social_links_active`
 * exist for. Ordering is applied here rather than in the service because the
 * database can do it from the index for free.
 */
final class NavigationRepository extends Repository
{
    /**
     * Active menu links, in display order.
     *
     * @return list<array<string,mixed>>
     */
    public function activeMenuLinks(): array
    {
        return $this->all(
            'SELECT id, location, label, url, parent_id, sort_order, open_in_new_tab
               FROM menu_links
              WHERE is_active = 1
              ORDER BY location, sort_order, label'
        );
    }

    /** @return list<array<string,mixed>> */
    public function activeSocialLinks(): array
    {
        return $this->all(
            'SELECT id, platform, url, icon_name, sort_order
               FROM social_links
              WHERE is_active = 1
              ORDER BY sort_order, platform'
        );
    }
}

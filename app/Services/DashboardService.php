<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Auth\Role;
use Rajdhani\Helpers\DateHelper;
use Rajdhani\Repositories\DashboardRepository;

/**
 * `GET /admin/dashboard/summary` (doc §9, §11; RTPP-36) — every role holds
 * `Capability::DASHBOARD` READ (§7.3), but Sales is scoped to "leads only"
 * in that same row, and `pending_reviews` is the one count that isn't a
 * lead — a Sales admin has no `REVIEWS` capability at all, so a number that
 * exists only to link into the review moderation queue would be dead weight
 * in their dashboard, not a harmless extra.
 *
 * The chart window and the recent-leads list length are display defaults
 * (no number is confirmed anywhere in the document for either), chosen the
 * same way `Pagination::DEFAULT_LIMIT` was: a reasonable constant, not a
 * business policy that belongs in `settings`.
 */
final class DashboardService
{
    private const CHART_DAYS = 30;
    private const RECENT_LEADS_LIMIT = 10;

    public function __construct(
        private readonly DashboardRepository $dashboard = new DashboardRepository(),
    ) {
    }

    /** @return array<string,mixed> */
    public function summary(?Role $role): array
    {
        $counts = $this->dashboard->counts();

        if ($role === Role::SALES) {
            unset($counts['pending_reviews']);
        }

        return [
            'counts'       => $counts,
            'chart'        => $this->dashboard->submissionsChart(self::CHART_DAYS),
            'recent_leads' => array_map($this->leadView(...), $this->dashboard->recentLeads(self::RECENT_LEADS_LIMIT)),
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function leadView(array $row): array
    {
        return [
            'type'       => (string) $row['type'],
            'id'         => (string) $row['id'],
            'name'       => (string) $row['name'],
            'email'      => (string) $row['email'],
            'created_at' => DateHelper::iso((string) $row['created_at']),
        ];
    }
}

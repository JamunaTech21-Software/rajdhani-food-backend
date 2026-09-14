<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

/**
 * `GET /admin/dashboard/summary` (doc §9, §11; RTPP-36).
 *
 * A dedicated repository rather than reusing `EnquiryRepository`,
 * `DealerApplicationRepository`, `ContactMessageRepository`,
 * `ReviewRepository` and `NewsletterSubscriberRepository`'s own
 * paginate/count methods — same reasoning as `LeadDigestRepository`'s own
 * class doc: a dashboard-shaped cross-table report is not the filtered
 * listing shape those services already expose.
 *
 * "New"/"unread"/"pending" map to each table's own default, not-yet-actioned
 * status: `NEW` (enquiries), `SUBMITTED` (dealer applications, whose enum has
 * no literal `NEW`), `UNREAD` (contact messages), `PENDING` (reviews).
 */
final class DashboardRepository extends Repository
{
    /**
     * @return array{new_enquiries:int,new_applications:int,unread_messages:int,pending_reviews:int,subscribers:int}
     */
    public function counts(): array
    {
        return [
            'new_enquiries'    => (int) $this->scalar("SELECT COUNT(*) FROM product_enquiries WHERE status = 'NEW'"),
            'new_applications' => (int) $this->scalar("SELECT COUNT(*) FROM dealer_applications WHERE status = 'SUBMITTED'"),
            'unread_messages'  => (int) $this->scalar("SELECT COUNT(*) FROM contact_messages WHERE status = 'UNREAD'"),
            'pending_reviews'  => (int) $this->scalar("SELECT COUNT(*) FROM reviews WHERE status = 'PENDING'"),
            'subscribers'      => (int) $this->scalar('SELECT COUNT(*) FROM newsletter_subscribers WHERE is_subscribed = 1'),
        ];
    }

    /**
     * One day-by-day series across the three submission tables — the "leads"
     * dashboard cards cover (subscribers are a running total, not a daily
     * submission event, so they are not part of this chart).
     *
     * Three grouped queries, not one query per day: `$days` is a chart
     * window, not a row count, and the zero-filled calendar is built in PHP
     * so a day with no submissions still appears as a `0`, not a gap the
     * front-end's chart library would have to fill in itself.
     *
     * @return list<array{date:string,enquiries:int,applications:int,messages:int}>
     */
    public function submissionsChart(int $days): array
    {
        $since = $this->now(-$days * 86400);

        $enquiries = $this->dailyCounts('product_enquiries', $since);
        $applications = $this->dailyCounts('dealer_applications', $since);
        $messages = $this->dailyCounts('contact_messages', $since);

        $series = [];
        $cursor = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $cursor->modify("-{$i} days")->format('Y-m-d');

            $series[] = [
                'date'         => $date,
                'enquiries'    => $enquiries[$date] ?? 0,
                'applications' => $applications[$date] ?? 0,
                'messages'     => $messages[$date] ?? 0,
            ];
        }

        return $series;
    }

    /** @return array<string,int> date (Y-m-d) => count */
    private function dailyCounts(string $table, string $since): array
    {
        // $table is a fixed literal from the two call sites above, never
        // request input.
        $rows = $this->all(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$table} WHERE created_at >= :since GROUP BY DATE(created_at)",
            [':since' => $since],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['d']] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * The most recent submissions across all three lead tables, merged and
     * re-sorted — the dashboard's "recent leads" widget (doc §11).
     *
     * @return list<array<string,mixed>>
     */
    public function recentLeads(int $limit): array
    {
        return $this->all(
            "(SELECT 'enquiry' AS type, id, name, email, created_at FROM product_enquiries)
             UNION ALL
             (SELECT 'dealer_application' AS type, id, full_name AS name, email, created_at FROM dealer_applications)
             UNION ALL
             (SELECT 'contact_message' AS type, id, name, email, created_at FROM contact_messages)
             ORDER BY created_at DESC
             LIMIT :limit",
            [':limit' => $limit],
        );
    }
}

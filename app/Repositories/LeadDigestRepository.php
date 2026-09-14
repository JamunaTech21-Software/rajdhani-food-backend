<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

/**
 * The counts `Jobs\LeadDigest` (doc §13, §14.4; RTPP-33) reads — one
 * dedicated repository rather than reusing `EnquiryRepository`,
 * `DealerApplicationRepository`, `ContactMessageRepository` and
 * `NewsletterSubscriberRepository`'s own list/paginate methods, because a
 * "how many in the last 7 days" count across four tables is a one-off admin
 * report, not a filtered listing those services already expose the shape
 * for.
 */
final class LeadDigestRepository extends Repository
{
    /**
     * @return array{enquiries:int,applications:int,messages:int,subscribers:int}
     */
    public function weeklyCounts(int $sinceDaysAgo = 7): array
    {
        $since = $this->now(-$sinceDaysAgo * 86400);

        return [
            'enquiries'    => $this->countSince('product_enquiries', 'created_at', $since),
            'applications' => $this->countSince('dealer_applications', 'created_at', $since),
            'messages'     => $this->countSince('contact_messages', 'created_at', $since),
            'subscribers'  => $this->countSince('newsletter_subscribers', 'subscribed_at', $since),
        ];
    }

    private function countSince(string $table, string $column, string $since): int
    {
        // $table/$column are fixed literals from the method above, never
        // request input — the one place this repository builds SQL outside
        // a prepared parameter, and it is not reachable from outside it.
        return (int) $this->scalar(
            "SELECT COUNT(*) FROM {$table} WHERE {$column} >= :since",
            [':since' => $since],
        );
    }
}

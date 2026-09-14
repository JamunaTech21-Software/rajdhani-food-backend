<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `newsletter_subscribers` (doc §8.8, §9.6, §9.10, §11; RTPP-31).
 *
 * `is_subscribed` is a flag, not a delete: `uq_newsletter_email` means the
 * same address can never have two rows, so "unsubscribe" has to be a state
 * change rather than a delete-and-later-reinsert — the row, its
 * `unsubscribe_token`, and its subscription history persist across a
 * resubscribe. Only the admin's explicit `DELETE /admin/subscribers/:id`
 * removes a row outright.
 */
final class NewsletterSubscriberRepository extends Repository
{
    private const COLUMNS = 'id, email, is_subscribed, unsubscribe_token, source, subscribed_at, unsubscribed_at';

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM newsletter_subscribers WHERE email = :email', [':email' => $email]);
    }

    /** @return array<string,mixed>|null */
    public function findByToken(string $token): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM newsletter_subscribers WHERE unsubscribe_token = :token',
            [':token' => $token],
        );
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM newsletter_subscribers WHERE id = :id', [':id' => $id]);
    }

    /**
     * A brand-new subscriber. Re-subscribing an address already on the list
     * is `reactivate()`, not this — `email` is unique, so this must only be
     * called once `findByEmail()` has confirmed no row exists yet.
     */
    public function create(string $email, string $unsubscribeToken, ?string $source): string
    {
        $id = UlidHelper::generate();

        $this->run(
            'INSERT INTO newsletter_subscribers (id, email, is_subscribed, unsubscribe_token, source, subscribed_at, unsubscribed_at)
             VALUES (:id, :email, 1, :token, :source, :subscribed_at, NULL)',
            [':id' => $id, ':email' => $email, ':token' => $unsubscribeToken, ':source' => $source, ':subscribed_at' => $this->now()],
        );

        return $id;
    }

    /** Re-activates a previously unsubscribed row — same token, fresh `subscribed_at`, `unsubscribed_at` cleared. */
    public function reactivate(string $id, ?string $source): int
    {
        return $this->run(
            'UPDATE newsletter_subscribers
             SET is_subscribed = 1, source = :source, subscribed_at = :subscribed_at, unsubscribed_at = NULL
             WHERE id = :id',
            [':id' => $id, ':source' => $source, ':subscribed_at' => $this->now()],
        );
    }

    public function unsubscribe(string $id): int
    {
        return $this->run(
            'UPDATE newsletter_subscribers SET is_subscribed = 0, unsubscribed_at = :unsubscribed_at WHERE id = :id',
            [':id' => $id, ':unsubscribed_at' => $this->now()],
        );
    }

    /** @return list<array<string,mixed>> */
    public function paginate(int $limit, int $offset, ?bool $isSubscribed): array
    {
        [$where, $parameters] = $this->filter($isSubscribed);
        $parameters[':limit'] = $limit;
        $parameters[':offset'] = $offset;

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM newsletter_subscribers
              {$where}
              ORDER BY subscribed_at DESC
              LIMIT :limit OFFSET :offset",
            $parameters,
        );
    }

    public function count(?bool $isSubscribed): int
    {
        [$where, $parameters] = $this->filter($isSubscribed);

        return (int) $this->scalar("SELECT COUNT(*) FROM newsletter_subscribers {$where}", $parameters);
    }

    /**
     * Every matching row, unpaginated — `NewsletterService::exportCsv()`'s
     * only caller, same reasoning as every other export in this codebase.
     *
     * @return list<array<string,mixed>>
     */
    public function listAll(?bool $isSubscribed): array
    {
        [$where, $parameters] = $this->filter($isSubscribed);

        return $this->all(
            'SELECT ' . self::COLUMNS . " FROM newsletter_subscribers {$where} ORDER BY subscribed_at DESC",
            $parameters,
        );
    }

    public function delete(string $id): int
    {
        return $this->run('DELETE FROM newsletter_subscribers WHERE id = :id', [':id' => $id]);
    }

    /** @return array{0:string,1:array<string,scalar|null>} */
    private function filter(?bool $isSubscribed): array
    {
        if ($isSubscribed === null) {
            return ['', []];
        }

        return ['WHERE is_subscribed = :is_subscribed', [':is_subscribed' => $isSubscribed]];
    }
}

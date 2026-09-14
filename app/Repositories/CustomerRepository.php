<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

use Rajdhani\Helpers\UlidHelper;

/**
 * `customers` (doc §8.3).
 *
 * Two unique keys matter and they pull in different directions: `google_id`
 * identifies the Google account, `email` identifies the person. They are looked
 * up in that order — see CustomerAuthService::signInWithGoogle() for why the
 * order is load-bearing rather than incidental.
 */
final class CustomerRepository extends Repository
{
    private const COLUMNS = 'id, google_id, email, name, avatar_url, phone, city, company_name,
                             email_verified, is_blocked, last_login_at, created_at, updated_at';

    /** @return array<string,mixed>|null */
    public function findByGoogleId(string $googleId): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM customers WHERE google_id = :google_id LIMIT 1',
            [':google_id' => $googleId],
        );
    }

    /** @return array<string,mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM customers WHERE email = :email LIMIT 1',
            [':email' => $email],
        );
    }

    /** @return array<string,mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->one(
            'SELECT ' . self::COLUMNS . ' FROM customers WHERE id = :id LIMIT 1',
            [':id' => $id],
        );
    }

    public function create(string $googleId, string $email, string $name, ?string $avatarUrl): string
    {
        $id = UlidHelper::generate();

        $this->run(
            'INSERT INTO customers
                (id, google_id, email, name, avatar_url, phone, city, company_name,
                 email_verified, is_blocked, last_login_at, created_at, updated_at)
             VALUES
                (:id, :google_id, :email, :name, :avatar_url, NULL, NULL, NULL,
                 1, 0, :last_login_at, :created_at, :updated_at)',
            [
                ':id'        => $id,
                ':google_id' => $googleId,
                ':email'     => $email,
                ':name'      => $name,

                // VARCHAR(512): a Google avatar URL carries sizing parameters
                // and can be long, but not that long.
                ':avatar_url'    => $avatarUrl === null ? null : mb_substr($avatarUrl, 0, 512),
                ':last_login_at' => $this->now(),
                ':created_at'    => $this->now(),
                ':updated_at'    => $this->now(),
            ],
        );

        return $id;
    }

    /**
     * Attach a Google account to a customer row that already exists under this
     * email. Doc §7.1 step 5: an account can predate Google linking.
     */
    public function linkGoogleAccount(string $id, string $googleId): void
    {
        $this->run(
            'UPDATE customers SET google_id = :google_id, email_verified = 1, updated_at = :updated_at
              WHERE id = :id',
            [':google_id' => $googleId, ':updated_at' => $this->now(), ':id' => $id],
        );
    }

    /**
     * Refresh the details Google owns.
     *
     * Name and avatar are Google's to change, so they are re-copied on each
     * sign-in. Email is deliberately not: it is a unique key here and other
     * rows may already reference this customer, so a change of address is a
     * migration rather than an update.
     */
    public function syncProfileFromGoogle(string $id, string $name, ?string $avatarUrl): void
    {
        $this->run(
            'UPDATE customers SET name = :name, avatar_url = :avatar_url, updated_at = :updated_at
              WHERE id = :id',
            [
                ':name'       => $name,
                ':avatar_url' => $avatarUrl === null ? null : mb_substr($avatarUrl, 0, 512),
                ':updated_at' => $this->now(),
                ':id'         => $id,
            ],
        );
    }

    public function touchLastLogin(string $id): void
    {
        $this->run(
            'UPDATE customers SET last_login_at = :last_login_at WHERE id = :id',
            [':last_login_at' => $this->now(), ':id' => $id],
        );
    }

    /** @param array<string,scalar|null> $fields phone, city, company_name — allowlisted by the service */
    public function updateProfile(string $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $assignments = [];
        $parameters = [':id' => $id, ':updated_at' => $this->now()];

        foreach ($fields as $column => $value) {
            $assignments[] = "`{$column}` = :{$column}";
            $parameters[":{$column}"] = $value;
        }

        $this->run(
            'UPDATE customers SET ' . implode(', ', $assignments) . ', updated_at = :updated_at WHERE id = :id',
            $parameters,
        );
    }

    /**
     * Delete the account and everything personal attached to it (doc §9.2).
     *
     * The foreign keys do the work: `wishlist_items`, `reviews` and
     * `refresh_tokens` are all ON DELETE CASCADE from `customers`. That means a
     * deletion also removes the customer's published reviews — which is the
     * schema's stated intent, and worth knowing before anyone calls it.
     */
    public function delete(string $id): void
    {
        $this->run('DELETE FROM customers WHERE id = :id', [':id' => $id]);
    }
}

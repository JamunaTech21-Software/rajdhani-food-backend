<?php

declare(strict_types=1);

namespace Rajdhani\Auth;

/**
 * The three admin roles (doc §7.3). Mirrors the ENUM on `admin_users.role`.
 *
 * `tryFromClaim()` exists because the role arrives inside a signed JWT, which
 * makes it trustworthy but not necessarily *current* — a role renamed in a later
 * migration would still be sitting in tokens issued before it. An unknown value
 * resolves to null and is then treated as no access at all, which is the only
 * safe reading of "I do not recognise this role".
 */
enum Role: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case EDITOR      = 'EDITOR';
    case SALES       = 'SALES';

    public static function tryFromClaim(mixed $claim): ?self
    {
        return is_string($claim) ? self::tryFrom($claim) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Admin',
            self::EDITOR      => 'Editor',
            self::SALES       => 'Sales',
        };
    }
}

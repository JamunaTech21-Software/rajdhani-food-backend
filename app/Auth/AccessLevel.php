<?php

declare(strict_types=1);

namespace Rajdhani\Auth;

/**
 * How much of a capability a role has (doc §7.3).
 *
 * A total order, so "does this role have at least what this route needs" is one
 * comparison. The levels are deliberately coarse — the matrix in §7.3 has only
 * four distinct cells and inventing more would mean inventing rules the
 * document does not contain.
 *
 * OWN is the odd one and exists for a single row: an Editor may delete media
 * they uploaded themselves, but not media somebody else uploaded. That is a
 * *row-level* rule, which a middleware cannot decide — it sees a request, not a
 * row. So a route gated at OWN admits the request and RequireRole records the
 * scope on it; the service is then obliged to filter. See RolePolicy::scopeFor().
 */
enum AccessLevel: int
{
    case NONE = 0;
    case READ = 1;
    case OWN = 2;
    case WRITE = 3;

    /** Does holding this level satisfy a route that requires `$required`? */
    public function satisfies(self $required): bool
    {
        return $this->value >= $required->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::NONE  => 'none',
            self::READ  => 'read',
            self::OWN   => 'own',
            self::WRITE => 'write',
        };
    }
}

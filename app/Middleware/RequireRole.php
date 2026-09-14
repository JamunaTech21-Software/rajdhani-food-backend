<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Auth\AccessLevel;
use Rajdhani\Auth\Capability;
use Rajdhani\Auth\Role;
use Rajdhani\Auth\RolePolicy;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;

/**
 * Enforces the §7.3 permission matrix on an admin route.
 *
 * Always runs *after* RequireAdmin, which establishes who the caller is. This
 * one decides what they may do — separating the two means a route can require
 * authentication without authorisation (`/auth/admin/me`), and means the role
 * check is impossible to satisfy by accident: it reads the role from a request
 * attribute only RequireAdmin sets, from a claim inside a signed token.
 *
 * Unlike every other middleware here it is *configured*, not just named, so it
 * is registered as an instance:
 *
 *     $r->get('/admin/products', …, [RequireAdmin::class, RequireRole::read(Capability::PRODUCTS)]);
 *     $r->post('/admin/products', …, [RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)]);
 *
 * **An unrecognised role is denied**, not defaulted. A token minted before a
 * role was renamed still carries the old string, and guessing what it meant is
 * how privilege escalation gets shipped.
 */
final class RequireRole implements Middleware
{
    private function __construct(
        private readonly Capability $capability,
        private readonly AccessLevel $required,
    ) {
    }

    /** Read-only access: listing and viewing. */
    public static function read(Capability $capability): self
    {
        return new self($capability, AccessLevel::READ);
    }

    /** Full access: create, update, delete anything in this capability. */
    public static function write(Capability $capability): self
    {
        return new self($capability, AccessLevel::WRITE);
    }

    /**
     * Access to rows the caller owns.
     *
     * Admits both a WRITE holder (scope `all`) and an OWN holder (scope `own`),
     * and records which on the request. **A route gated this way is only half
     * protected by the middleware** — the service must read `row_scope` and
     * filter. Used for the Editor's media-delete cell, the single OWN in §7.3.
     */
    public static function own(Capability $capability): self
    {
        return new self($capability, AccessLevel::OWN);
    }

    public function handle(Request $request, callable $next): mixed
    {
        $role = Role::tryFromClaim($request->attribute('admin_role'));

        if ($role === null) {
            // Either RequireAdmin did not run — a wiring bug — or the token
            // carries a role this build does not know. Both are refusals.
            throw ApiError::forbidden('You do not have permission to do that');
        }

        if (!RolePolicy::allows($role, $this->capability, $this->required)) {
            throw ApiError::forbidden(sprintf(
                '%s access to %s is not available to a %s',
                $this->required->label(),
                $this->capability->label(),
                $role->label(),
            ));
        }

        // Read by services handling an OWN-gated route. 'all' for a full
        // holder, 'own' for one limited to their own rows.
        $request->setAttribute('row_scope', RolePolicy::scopeFor($role, $this->capability));

        return $next($request);
    }
}

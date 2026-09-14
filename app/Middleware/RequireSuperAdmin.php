<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Auth\Role;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;

/**
 * Restricts one specific route beyond what its capability's WRITE cell
 * already allows (doc §9.10, RTPP-27).
 *
 * §7.3's "Review moderation" row is a single WRITE cell shared by Editor and
 * Super Admin — correct for approve/reject, the actions the row actually
 * describes. But §9.10's route table is more specific than §7.3's coarse
 * per-module row: `DELETE /admin/reviews/:id` is listed as **Super Admin**
 * only, a hard delete with no undo, stricter than the capability the rest of
 * the resource shares. `RolePolicy`'s matrix has no way to express "WRITE,
 * but only for one role" *within* a single capability — it is deliberately
 * one row per capability (see `Capability`'s class doc) — so this is a
 * second, narrower gate stacked after `RequireRole::write()`, not a change
 * to the matrix's shape. Composed as:
 *
 *     [RequireAdmin::class, RequireRole::write(Capability::REVIEWS), RequireSuperAdmin::class]
 *
 * Reach for this only when a specific route's documented role is strictly
 * narrower than its capability's row — inventing a matrix row for a
 * distinction the document itself never draws would be the wrong fix.
 */
final class RequireSuperAdmin implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        $role = Role::tryFromClaim($request->attribute('admin_role'));

        if ($role !== Role::SUPER_ADMIN) {
            throw ApiError::forbidden('Only a Super Admin may do that');
        }

        return $next($request);
    }
}

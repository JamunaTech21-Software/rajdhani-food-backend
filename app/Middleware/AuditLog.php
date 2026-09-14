<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Closure;
use Rajdhani\Http\Request;
use Rajdhani\Kernel;
use Rajdhani\Repositories\AuditLogRepository;
use Rajdhani\Support\AuditDiff;
use Throwable;

/**
 * Records one row per admin mutation (doc §3.1, §13; RTPP-35).
 *
 * Registered per route with a `state` closure that re-reads the current row
 * through the resource's own repository — called once before the handler
 * runs and once after, rather than trusting whatever shape the controller
 * happens to return. That keeps this middleware decoupled from every
 * controller's response envelope (some return the row directly, some wrap it
 * in `['data' => …]`), and it is what makes a delete answer "after: null" for
 * free: the same finder simply returns nothing the second time, whether the
 * row was hard-deleted or soft-deleted (every `find()` in this codebase
 * already excludes `deleted_at`).
 *
 * A route with no id in its path — `POST`, `/reorder`, `/bulk-approve` — has
 * nothing to re-read beforehand, so `before` is always null there and `after`
 * falls back to the controller's own return value, which for every `create`
 * in this codebase already is the full created row.
 *
 * Usage, from `routes/admin.php`:
 *
 *     new AuditLog('Banner', state: fn (Request $r) => (new BannerRepository())->find((string) $r->attribute('id')))
 *
 * A nested child resource (a product's pack size) needs `idAttribute`,
 * because its own id is not the route's `:id`:
 *
 *     new AuditLog(
 *         'ProductPackSize',
 *         state: fn (Request $r) => (new ProductRepository())->findPackSize(
 *             (string) $r->attribute('id'),
 *             (string) $r->attribute('packSizeId'),
 *         ),
 *         idAttribute: 'packSizeId',
 *     )
 *
 * A resource with no id at all — the site profile singleton, the settings
 * key/value store — sets `singleton: true` so the before/after re-read still
 * happens despite there being no id to check for.
 */
final class AuditLog implements Middleware
{
    /**
     * @param (Closure(Request):(array<string,mixed>|null))|null $state a closure that re-reads the current
     *        row through the resource's own repository/service — called both before and after the handler.
     *        Omitted for a create-only route, where there is nothing to re-read beforehand.
     * @param string $idAttribute the route attribute holding this resource's own id — override for a nested
     *        child resource whose id is not the route's `:id`
     * @param bool $singleton true for a resource with no id in its route at all
     */
    public function __construct(
        private readonly string $entityType,
        private readonly ?Closure $state = null,
        private readonly ?string $action = null,
        private readonly string $idAttribute = 'id',
        private readonly bool $singleton = false,
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    public function handle(Request $request, callable $next): mixed
    {
        $hasState = $this->state !== null
            && ($this->singleton || is_string($request->attribute($this->idAttribute)));

        $before = $hasState ? ($this->state)($request) : null;

        $result = $next($request);

        $after = $hasState ? ($this->state)($request) : (is_array($result) ? $result : null);

        $this->write($request, $before, $after);

        return $result;
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    private function write(Request $request, ?array $before, ?array $after): void
    {
        $diff = AuditDiff::compact($before, $after);

        $idFromRoute = $request->attribute($this->idAttribute);
        $entityId = is_string($idFromRoute) ? $idFromRoute : $this->extractId($after);
        $adminId = $request->attribute('admin_id');

        try {
            $this->audit->record(
                is_string($adminId) ? $adminId : null,
                strtolower($this->entityType) . '.' . ($this->action ?? $this->defaultAction($request->method)),
                $this->entityType,
                $entityId,
                $diff['before'],
                $diff['after'],
                $request->ip,
            );
        } catch (Throwable $e) {
            // The mutation itself already succeeded and its response is
            // already decided — a failure to record the *audit* of it is a
            // bug in this table or this connection, not a reason to turn an
            // already-completed write into a 500. Same trade-off as Mailer
            // (RTPP-33): the primary action never fails for a secondary one.
            Kernel::logger()->error('Audit log write failed', [
                'entity_type' => $this->entityType,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    private function defaultAction(string $method): string
    {
        return match ($method) {
            'POST'         => 'create',
            'PATCH', 'PUT' => 'update',
            'DELETE'       => 'delete',
            default        => strtolower($method),
        };
    }

    /** @param array<string,mixed>|null $row */
    private function extractId(?array $row): ?string
    {
        $value = $row['id'] ?? null;

        return is_string($value) ? $value : null;
    }
}

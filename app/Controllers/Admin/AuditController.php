<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Http\Request;
use Rajdhani\Services\AuditService;

/**
 * `/admin/audit-logs` (doc §11, RTPP-35). Parse, delegate, respond.
 */
final class AuditController
{
    public function __construct(
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->audit->paginate($request->query);
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Auth\Role;
use Rajdhani\Http\Request;
use Rajdhani\Services\DashboardService;

/**
 * `/admin/dashboard` (doc §9, §11, RTPP-36). Parse, delegate, respond.
 */
final class DashboardController
{
    public function __construct(
        private readonly DashboardService $dashboard = new DashboardService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function summary(Request $request): array
    {
        return $this->dashboard->summary(Role::tryFromClaim($request->attribute('admin_role')));
    }
}

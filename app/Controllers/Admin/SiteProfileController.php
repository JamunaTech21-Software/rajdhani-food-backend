<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Http\Request;
use Rajdhani\Services\SiteProfileService;

/**
 * `/admin/site-profile` (doc §9.8) — Super Admin only, per the §7.3 matrix.
 *
 * The read here is narrower than `GET /public/layout`, which serves the same row
 * to everyone: this one exists so the settings screen can load the current
 * values before editing them, and it is gated because settings are a
 * Super-Admin capability.
 */
final class SiteProfileController
{
    public function __construct(
        private readonly SiteProfileService $siteProfile = new SiteProfileService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->siteProfile->layout();
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->siteProfile->update($request->body);
    }
}

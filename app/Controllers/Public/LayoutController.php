<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\SiteProfileService;

/**
 * `GET /public/layout` (doc §9.3).
 *
 * The first call the customer site and the admin dashboard both make. It takes
 * no authentication, no header and no query string — under v2.0 this is where
 * `X-Brand` would have gone, and its absence is the observable part of the
 * single-site cut (doc §6, rule 5).
 */
final class LayoutController
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
}

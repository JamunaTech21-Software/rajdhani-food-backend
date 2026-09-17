<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\StatCounterService;

/**
 * `GET /public/stats?group=` (doc §9.3, §10.1; RTPP-67) — one group's
 * count-up band. `group` is required; see
 * `StatCounterService::publicByGroup()`'s doc for why. Generalises
 * `HomeService`'s own hard-coded `HOME` group to every page that has one.
 */
final class StatCounterController
{
    public function __construct(
        private readonly StatCounterService $stats = new StatCounterService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(Request $request): array
    {
        return $this->stats->publicByGroup($request->query);
    }
}

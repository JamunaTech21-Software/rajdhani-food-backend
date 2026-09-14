<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Services\CachePurgeService;

/**
 * `/admin/cache` (doc §9, §14.3, RTPP-36). Parse, delegate, respond.
 */
final class CacheController
{
    public function __construct(
        private readonly CachePurgeService $purge = new CachePurgeService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function purge(): array
    {
        $this->purge->purge();

        return ['purged' => true];
    }
}

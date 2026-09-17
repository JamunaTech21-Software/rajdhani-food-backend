<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\FeatureItemService;

/**
 * `GET /public/feature-items?section=` (doc §9.3, §10.1; RTPP-67) — the
 * icon strip for one section. `section` is required; see
 * `FeatureItemService::publicBySection()`'s doc for why.
 */
final class FeatureItemController
{
    public function __construct(
        private readonly FeatureItemService $items = new FeatureItemService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(Request $request): array
    {
        return $this->items->publicBySection($request->query);
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\BannerService;

/**
 * `GET /public/banners?placement=...` (doc §9.4-adjacent, §10.1; RTPP-23).
 * Active, in-window banners for one placement, in slider order — the
 * PUBLISHED-only and schedule-window rules both live in `BannerService`.
 */
final class BannerController
{
    public function __construct(
        private readonly BannerService $banners = new BannerService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return ['data' => $this->banners->publicList($request->query)];
    }
}

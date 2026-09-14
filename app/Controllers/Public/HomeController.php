<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Services\HomeService;

/**
 * `GET /public/home` (doc §9.3, §14.1; RTPP-36) — the composed home payload.
 * No parameters, no authentication: the first call the home page makes.
 */
final class HomeController
{
    public function __construct(
        private readonly HomeService $home = new HomeService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function show(): array
    {
        return $this->home->home();
    }
}

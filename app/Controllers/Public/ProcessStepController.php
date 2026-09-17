<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\ProcessStepService;

/**
 * `GET /public/process-steps?group=` (doc §9.3, §10.4; RTPP-67) — one
 * timeline's steps, in `step_number` order. `group` is required; see
 * `ProcessStepService::publicByGroup()`'s doc for why.
 */
final class ProcessStepController
{
    public function __construct(
        private readonly ProcessStepService $steps = new ProcessStepService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(Request $request): array
    {
        return $this->steps->publicByGroup($request->query);
    }
}

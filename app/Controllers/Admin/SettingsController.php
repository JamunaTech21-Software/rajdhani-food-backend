<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Http\Request;
use Rajdhani\Services\SettingsService;

/**
 * `/admin/settings` (doc §9.8, RTPP-32). Parse, delegate, respond.
 */
final class SettingsController
{
    public function __construct(
        private readonly SettingsService $settings = new SettingsService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(): array
    {
        return ['data' => $this->settings->index()];
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return ['data' => $this->settings->update($request->body)];
    }
}

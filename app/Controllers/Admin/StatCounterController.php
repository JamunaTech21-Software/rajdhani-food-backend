<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\StatCounterService;

/**
 * `/admin/stat-counters` (doc §11, RTPP-24). Parse, delegate, respond.
 */
final class StatCounterController
{
    public function __construct(
        private readonly StatCounterService $stats = new StatCounterService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return ['data' => $this->stats->list($request->query)];
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->stats->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->stats->create($request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->stats->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->stats->delete($this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->stats->reorder($request->body);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such stat counter');
        }

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\DealerApplicationService;

/**
 * `/admin/applications` (doc §9.10, RTPP-30). Parse, delegate, respond.
 */
final class DealerApplicationController
{
    public function __construct(
        private readonly DealerApplicationService $applications = new DealerApplicationService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->applications->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->applications->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->applications->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function export(Request $request): array
    {
        return $this->applications->exportCsv($request->query);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such application');
        }

        return $id;
    }
}

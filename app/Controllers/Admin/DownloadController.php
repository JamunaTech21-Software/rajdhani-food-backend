<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\DownloadService;

/**
 * `/admin/downloads` (doc §9.9, RTPP-32). Parse, delegate, respond.
 */
final class DownloadController
{
    public function __construct(
        private readonly DownloadService $downloads = new DownloadService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(): array
    {
        return ['data' => $this->downloads->list()];
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->downloads->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->downloads->create($request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->downloads->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->downloads->delete($this->id($request));

        return ['deleted' => true];
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such download');
        }

        return $id;
    }
}

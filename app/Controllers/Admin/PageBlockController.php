<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\PageBlockService;

/**
 * `/admin/page-blocks` (doc §11, RTPP-24). Parse, delegate, respond.
 */
final class PageBlockController
{
    public function __construct(
        private readonly PageBlockService $blocks = new PageBlockService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return ['data' => $this->blocks->list($request->query)];
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->blocks->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->blocks->create($request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->blocks->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->blocks->delete($this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->blocks->reorder($request->body);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such page block');
        }

        return $id;
    }
}

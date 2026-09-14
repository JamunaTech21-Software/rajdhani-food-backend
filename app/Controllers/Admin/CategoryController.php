<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\CategoryService;

/**
 * `/admin/categories` (doc §9.9, RTPP-18). Parse, delegate, respond — every
 * rule lives in the service, per the layering contract (doc §13).
 */
final class CategoryController
{
    public function __construct(
        private readonly CategoryService $categories = new CategoryService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->categories->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->categories->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->categories->create($request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->categories->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->categories->delete($this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->categories->reorder($request->body);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        // Absent only if this method is reached from a route with no :id
        // segment — a wiring mistake, not a client error, but still refused
        // rather than trusted as a string by an unchecked cast.
        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such category');
        }

        return $id;
    }
}

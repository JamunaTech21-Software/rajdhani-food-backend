<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\NewsService;

/**
 * `/admin/news` (doc §9.9, RTPP-26). Parse, delegate, respond.
 */
final class NewsController
{
    public function __construct(
        private readonly NewsService $news = new NewsService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->news->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->news->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->news->create($this->adminId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->news->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->news->delete($this->id($request));

        return ['deleted' => true];
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such news post');
        }

        return $id;
    }

    private function adminId(Request $request): string
    {
        // Set by RequireAdmin. Absent means the route was registered without
        // that middleware, which is a wiring bug rather than a client error.
        $id = $request->attribute('admin_id');

        if (!is_string($id) || $id === '') {
            throw ApiError::unauthenticated('Authentication required');
        }

        return $id;
    }
}

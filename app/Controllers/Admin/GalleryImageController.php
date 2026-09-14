<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\GalleryImageService;

/**
 * `/admin/gallery/images` (doc §9.9, RTPP-25). Parse, delegate, respond.
 */
final class GalleryImageController
{
    public function __construct(
        private readonly GalleryImageService $images = new GalleryImageService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->images->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->images->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->images->create($request->body);
    }

    /** @return array<string,mixed> */
    public function bulkStore(Request $request): array
    {
        return $this->images->bulkCreate($request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->images->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->images->delete($this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->images->reorder($request->body);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such gallery image');
        }

        return $id;
    }
}

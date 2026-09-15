<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\MediaService;

/**
 * `/admin/media` (doc §11, §12; RTPP-21, RTPP-22, RTPP-91). Parse, delegate,
 * respond — the signing algorithm and every registration/edit rule live in
 * `MediaService`.
 */
final class MediaController
{
    public function __construct(
        private readonly MediaService $media = new MediaService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->media->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->media->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function signature(Request $request): array
    {
        return $this->media->signature($request->body);
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->media->register($this->adminId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->media->update($this->id($request), $request->body, $this->adminId($request), $this->rowScope($request));
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->media->delete($this->id($request), $this->adminId($request), $this->rowScope($request));

        return ['deleted' => true];
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such media asset');
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

    private function rowScope(Request $request): string
    {
        // Set by RequireRole::own(). Absent means the route was registered
        // without it, which is a wiring bug — default to the narrowest
        // reading rather than trusting a caller who reached here without it.
        $scope = $request->attribute('row_scope');

        return is_string($scope) ? $scope : 'own';
    }
}

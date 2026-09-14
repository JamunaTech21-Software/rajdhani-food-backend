<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\SocialLinkService;

/**
 * `/admin/social-links` (doc §9.8, RTPP-32). Parse, delegate, respond.
 */
final class SocialLinkController
{
    public function __construct(
        private readonly SocialLinkService $links = new SocialLinkService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(): array
    {
        return ['data' => $this->links->list()];
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->links->find($this->id($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->links->create($request->body);
    }

    /** @return array<string,mixed> */
    public function update(Request $request): array
    {
        return $this->links->update($this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->links->delete($this->id($request));

        return ['deleted' => true];
    }

    /** @return array<string,mixed> */
    public function reorder(Request $request): array
    {
        return $this->links->reorder($request->body);
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such social link');
        }

        return $id;
    }
}

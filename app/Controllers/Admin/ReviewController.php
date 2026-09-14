<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Admin;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\ReviewService;

/**
 * `/admin/reviews` (doc §9.10, RTPP-27). Parse, delegate, respond.
 */
final class ReviewController
{
    public function __construct(
        private readonly ReviewService $reviews = new ReviewService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->reviews->paginate($request->query);
    }

    /** @return array<string,mixed> */
    public function approve(Request $request): array
    {
        return $this->reviews->approve($this->moderatorId($request), $this->id($request));
    }

    /** @return array<string,mixed> */
    public function reject(Request $request): array
    {
        return $this->reviews->reject($this->moderatorId($request), $this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function bulkApprove(Request $request): array
    {
        return $this->reviews->bulkApprove($this->moderatorId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function bulkReject(Request $request): array
    {
        return $this->reviews->bulkReject($this->moderatorId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        $this->reviews->adminDelete($this->id($request));

        return ['deleted' => true];
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such review');
        }

        return $id;
    }

    private function moderatorId(Request $request): string
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

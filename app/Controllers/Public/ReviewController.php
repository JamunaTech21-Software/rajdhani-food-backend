<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\ReviewService;

/**
 * `GET/POST /public/products/{slug}/reviews` and
 * `GET/PATCH/DELETE /public/my/reviews...` (doc §9.4, §9.7; RTPP-27).
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
        return $this->reviews->publicList($this->slug($request), $request->query);
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->reviews->create($this->customerId($request), $this->slug($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function myIndex(Request $request): array
    {
        return ['data' => $this->reviews->myReviews($this->customerId($request))];
    }

    /** @return array<string,mixed> */
    public function myUpdate(Request $request): array
    {
        return $this->reviews->updateOwn($this->customerId($request), $this->id($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function myDestroy(Request $request): array
    {
        $this->reviews->deleteOwn($this->customerId($request), $this->id($request));

        return ['deleted' => true];
    }

    private function slug(Request $request): string
    {
        $slug = $request->attribute('slug');

        if (!is_string($slug) || $slug === '') {
            throw ApiError::notFound('No such product');
        }

        return $slug;
    }

    private function id(Request $request): string
    {
        $id = $request->attribute('id');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such review');
        }

        return $id;
    }

    private function customerId(Request $request): string
    {
        // Set by RequireCustomer. Absent means the route was registered
        // without that middleware, which is a wiring bug rather than a
        // client error.
        $id = $request->attribute('customer_id');

        if (!is_string($id) || $id === '') {
            throw ApiError::unauthenticated('Authentication required');
        }

        return $id;
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\WishlistService;

/**
 * `/public/wishlist` (doc §9.7, RTPP-28). Every route here requires a
 * customer token; wired in `routes/public.php`.
 */
final class WishlistController
{
    public function __construct(
        private readonly WishlistService $wishlist = new WishlistService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->wishlist->list($this->customerId($request));
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->wishlist->add($this->customerId($request), $request->body);
    }

    /** @return array<string,mixed> */
    public function destroy(Request $request): array
    {
        return $this->wishlist->remove($this->customerId($request), $this->productId($request));
    }

    /** @return array<string,mixed> */
    public function merge(Request $request): array
    {
        return $this->wishlist->merge($this->customerId($request), $request->body);
    }

    private function productId(Request $request): string
    {
        $id = $request->attribute('productId');

        if (!is_string($id) || $id === '') {
            throw ApiError::notFound('No such product');
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

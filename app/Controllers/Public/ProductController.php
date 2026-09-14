<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\ProductService;

/**
 * `/public/products` (doc §9.4, §10.2; RTPP-20). Listing, detail by slug, and
 * related-by-category — all PUBLISHED-only, all unauthenticated. Parse,
 * delegate, respond, same as every other controller (doc §13); the
 * PUBLISHED-only rule and the 404-on-anything-else rule both live in
 * `ProductService`, not here.
 */
final class ProductController
{
    public function __construct(
        private readonly ProductService $products = new ProductService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->products->publicPaginate($request->query);
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        return $this->products->publicFindBySlug($this->slug($request));
    }

    /** @return array<string,mixed> */
    public function related(Request $request): array
    {
        return ['data' => $this->products->publicRelated($this->slug($request), $request->query)];
    }

    private function slug(Request $request): string
    {
        $slug = $request->attribute('slug');

        if (!is_string($slug) || $slug === '') {
            throw ApiError::notFound('No such product');
        }

        return $slug;
    }
}

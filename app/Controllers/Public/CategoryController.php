<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\CategoryService;

/**
 * `GET /public/categories` (doc §9.4, RTPP-18) — active categories with
 * product counts, feeding the sticky filter bar and the header dropdown. No
 * authentication, no query parameters.
 */
final class CategoryController
{
    public function __construct(
        private readonly CategoryService $categories = new CategoryService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(Request $request): array
    {
        return $this->categories->publicList();
    }
}

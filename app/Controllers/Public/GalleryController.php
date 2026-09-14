<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\GalleryCategoryService;
use Rajdhani\Services\GalleryImageService;

/**
 * `GET /public/gallery/categories` and `GET /public/gallery` (doc §9.5,
 * RTPP-25) — the tab bar and the masonry grid it filters.
 */
final class GalleryController
{
    public function __construct(
        private readonly GalleryCategoryService $categories = new GalleryCategoryService(),
        private readonly GalleryImageService $images = new GalleryImageService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function categories(Request $request): array
    {
        return ['data' => $this->categories->publicList()];
    }

    /** @return array<string,mixed> */
    public function index(Request $request): array
    {
        return $this->images->publicList($request->query);
    }
}

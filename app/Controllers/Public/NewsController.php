<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\NewsService;

/**
 * `GET /public/news`, `GET /public/news/featured`, `GET /public/news/{slug}`
 * (doc §9.5, §10.1, §10.4; RTPP-26).
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
        return $this->news->publicList($request->query);
    }

    /** @return array<string,mixed> */
    public function featured(Request $request): array
    {
        return ['data' => $this->news->publicFeatured()];
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        $slug = $request->attribute('slug');

        return $this->news->publicShow(is_string($slug) ? $slug : '');
    }
}

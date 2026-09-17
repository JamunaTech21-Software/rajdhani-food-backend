<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\PageBlockService;

/**
 * `GET /public/page-blocks/:pageKey` (doc §9.3, §10.4; RTPP-67) — every
 * published block on one static page, keyed by `block_key`. Returns `[]`
 * for a page with no published blocks yet, never a `404` — see
 * `PageBlockService::publicByPage()`'s doc.
 */
final class PageBlockController
{
    public function __construct(
        private readonly PageBlockService $blocks = new PageBlockService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(Request $request): array
    {
        $pageKey = $request->attribute('pageKey');

        if (!is_string($pageKey) || $pageKey === '') {
            throw ApiError::notFound('No such page');
        }

        return $this->blocks->publicByPage($pageKey);
    }
}

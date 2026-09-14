<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\DownloadService;

/**
 * `GET /public/downloads/:key` (doc §9.6, §10.3, RTPP-32). Resolves the
 * file URL and increments the download counter.
 */
final class DownloadController
{
    public function __construct(
        private readonly DownloadService $downloads = new DownloadService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function show(Request $request): array
    {
        $key = $request->attribute('key');

        if (!is_string($key) || $key === '') {
            throw ApiError::notFound('No such download');
        }

        return $this->downloads->resolve($key);
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use Rajdhani\Helpers\ApiError;

/**
 * Removing an asset from Cloudinary itself (doc §12, RTPP-22).
 *
 * An interface for the same reason `JwkSource` is one: `MediaService::delete()`
 * needs to be tested against every rejection and ordering case without a real
 * network call on every test run, and the one case that genuinely cannot be
 * tested offline — "does this actually delete the file" — gets its proof from
 * a live run against the real account instead (see RTPP-22's Jira comment).
 */
interface CloudinaryDestroyer
{
    /**
     * @throws ApiError if Cloudinary could not be reached or refused to
     *                  delete the asset — never for "already gone", which
     *                  is treated as success since it reaches the same end
     *                  state deletion is trying to reach
     */
    public function destroy(string $publicId, string $resourceType): void;
}

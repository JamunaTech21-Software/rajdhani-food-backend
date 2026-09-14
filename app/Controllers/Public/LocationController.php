<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;
use Rajdhani\Services\LocationService;

/**
 * `/public/locations` (doc §9.6, RTPP-30). Parse, delegate, respond.
 */
final class LocationController
{
    public function __construct(
        private readonly LocationService $locations = new LocationService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function districts(): array
    {
        return $this->locations->districts();
    }

    /** @return list<array<string,mixed>> */
    public function upazilas(Request $request): array
    {
        $districtId = $request->attribute('id');

        if (!is_string($districtId) || $districtId === '') {
            throw ApiError::notFound('No such district');
        }

        return $this->locations->upazilas($districtId);
    }
}

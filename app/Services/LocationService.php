<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\LocationRepository;

/**
 * The cascading district/upazila select behind the dealer application form
 * (doc §9.6, §10.3; RTPP-30). No write path — see `LocationRepository`'s
 * class doc.
 */
final class LocationService
{
    public function __construct(
        private readonly LocationRepository $locations = new LocationRepository(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function districts(): array
    {
        return array_map($this->districtView(...), $this->locations->districts());
    }

    /**
     * `GET /public/locations/districts/{id}/upazilas` — a district id that
     * does not exist is a `404`, not an empty list, so a stale dropdown
     * value on the front end is visibly wrong rather than silently empty.
     *
     * @return list<array<string,mixed>>
     */
    public function upazilas(string $districtId): array
    {
        if (!UlidHelper::isValid($districtId) || !$this->locations->districtExists($districtId)) {
            throw ApiError::notFound('No such district');
        }

        return array_map($this->upazilaView(...), $this->locations->upazilasForDistrict($districtId));
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function districtView(array $row): array
    {
        return [
            'id'            => (string) $row['id'],
            'name'          => (string) $row['name'],
            'name_bn'       => $row['name_bn'] === null ? null : (string) $row['name_bn'],
            'division_name' => $row['division_name'] === null ? null : (string) $row['division_name'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function upazilaView(array $row): array
    {
        return [
            'id'          => (string) $row['id'],
            'district_id' => (string) $row['district_id'],
            'name'        => (string) $row['name'],
            'name_bn'     => $row['name_bn'] === null ? null : (string) $row['name_bn'],
        ];
    }
}

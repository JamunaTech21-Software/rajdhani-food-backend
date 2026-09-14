<?php

declare(strict_types=1);

namespace Rajdhani\Repositories;

/**
 * `districts` and `upazilas` (doc §8.2, §8.8, §9.6; RTPP-30) — the cascading
 * select behind the dealer application form (§10.3).
 *
 * One repository for both tables rather than two: neither has a service-level
 * write path (they are seeded once from `bd-locations.json` and read-only
 * from here on — `LocationSeeder`'s own doc explains why upserting the seed
 * data is the only way either table ever changes), so there is no
 * create/update/delete surface to separate out. Every method here is a
 * lookup.
 */
final class LocationRepository extends Repository
{
    /** @return list<array<string,mixed>> */
    public function districts(): array
    {
        return $this->all('SELECT id, name, name_bn, division_name FROM districts ORDER BY name ASC');
    }

    public function districtExists(string $id): bool
    {
        return $this->scalar('SELECT id FROM districts WHERE id = :id', [':id' => $id]) !== null;
    }

    /** @return list<array<string,mixed>> */
    public function upazilasForDistrict(string $districtId): array
    {
        return $this->all(
            'SELECT id, district_id, name, name_bn FROM upazilas WHERE district_id = :district_id ORDER BY name ASC',
            [':district_id' => $districtId],
        );
    }

    /** Whether `$upazilaId` exists *and* belongs to `$districtId` — a mismatched pair is as invalid as a made-up id. */
    public function upazilaBelongsToDistrict(string $upazilaId, string $districtId): bool
    {
        return $this->scalar(
            'SELECT id FROM upazilas WHERE id = :id AND district_id = :district_id',
            [':id' => $upazilaId, ':district_id' => $districtId],
        ) !== null;
    }
}

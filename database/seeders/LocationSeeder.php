<?php

declare(strict_types=1);

namespace Rajdhani\Database\Seeders;

/**
 * The 64 districts of Bangladesh and their upazilas (doc 8.8, 10.3).
 *
 * This is the only genuinely *reference* data in the seed set: it is not
 * marketing copy, it does not belong to the client, and it is wrong rather than
 * merely stale if it drifts. Both tables are therefore upserted — a correction
 * made to the JSON reaches an existing database on the next run.
 *
 * The data is transcribed from the withdrawn v1 implementation's
 * `prisma/data/locations.ts` (RTPP-10 porting note) into
 * `seed-data/bd-locations.json`, which is the shared source for anything that
 * needs the list.
 *
 * Upazila spellings drive a public dropdown on the dealer application form and
 * have not been confirmed by the client (RTPP-17). District names are stable.
 */
final class LocationSeeder extends Seeder
{
    public function tables(): array
    {
        return ['districts', 'upazilas'];
    }

    public function run(): void
    {
        foreach ($this->load() as $district) {
            $districtId = $this->upsert(
                'districts',
                ['name' => $district['name']],
                ['division_name' => $district['division'], 'name_bn' => null],
            );

            foreach ($district['upazilas'] as $upazila) {
                // Unique on (district_id, name), so the same upazila name under
                // two districts — 'Sadar' repeats across the country — stays two
                // distinct rows.
                $this->upsert(
                    'upazilas',
                    ['district_id' => $districtId, 'name' => $upazila],
                    ['name_bn' => null],
                );
            }
        }
    }

    /** @return list<array{name:string,division:string,upazilas:list<string>}> */
    private function load(): array
    {
        /** @var list<array{name:string,division:string,upazilas:list<string>}> $districts */
        $districts = $this->seedData('bd-locations.json');

        return $districts;
    }
}

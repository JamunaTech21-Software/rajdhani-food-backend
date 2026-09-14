<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\LocationRepository;
use Rajdhani\Services\LocationService;

/**
 * The district/upazila lookups behind the dealer form's cascading select
 * (doc §9.6, §10.3; RTPP-30), against the real, already-seeded reference
 * data (64 districts, 493 upazilas — `LocationSeeder`).
 */
final class LocationTest extends DatabaseTestCase
{
    private LocationService $locations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locations = new LocationService(new LocationRepository($this->db));
    }

    public function testAllSixtyFourDistrictsAreReturnedAlphabetically(): void
    {
        $districts = $this->locations->districts();

        self::assertCount(64, $districts);

        $names = array_column($districts, 'name');
        $sorted = $names;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $names);
    }

    public function testUpazilasForADistrictBelongToThatDistrictOnly(): void
    {
        $dhaka = $this->districtIdByName('Dhaka');
        $comilla = $this->districtIdByName('Cumilla');

        $dhakaUpazilas = $this->locations->upazilas($dhaka);
        $comillaUpazilas = $this->locations->upazilas($comilla);

        self::assertNotEmpty($dhakaUpazilas);
        self::assertNotEmpty($comillaUpazilas);

        foreach ($dhakaUpazilas as $upazila) {
            self::assertSame($dhaka, $upazila['district_id']);
        }

        $dhakaIds = array_column($dhakaUpazilas, 'id');
        $comillaIds = array_column($comillaUpazilas, 'id');

        self::assertEmpty(array_intersect($dhakaIds, $comillaIds));
    }

    public function testAMadeUpDistrictIdIsNotFound(): void
    {
        $error = $this->captureApiError(fn () => $this->locations->upazilas(UlidHelper::generate()));

        self::assertSame(404, $error->status());
    }

    private function districtIdByName(string $name): string
    {
        $statement = $this->db->prepare('SELECT id FROM districts WHERE name = :name');
        $statement->execute([':name' => $name]);

        $id = $statement->fetchColumn();
        self::assertIsString($id, "Fixture district '{$name}' was not found — check bd-locations.json.");

        return $id;
    }

    private function captureApiError(callable $action): ApiError
    {
        try {
            $action();
        } catch (ApiError $e) {
            return $e;
        }

        self::fail('Expected an ApiError, none was thrown.');
    }
}

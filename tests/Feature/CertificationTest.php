<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\CertificationRepository;
use Rajdhani\Services\CertificationService;

/**
 * Certifications — flat, no grouping (doc §8.7, §10.4; RTPP-24), against a
 * real database that already carries real seeded rows. Tests here check
 * relative counts (before/after) and `assertContains`, never an absolute
 * list, since there is no filter to scope by the way grouped modules can.
 */
final class CertificationTest extends DatabaseTestCase
{
    private CertificationService $certifications;
    private CertificationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new CertificationRepository($this->db);
        $this->certifications = new CertificationService($this->repository);
    }

    public function testCreatingACertification(): void
    {
        $certification = $this->certifications->create(['name' => 'FSSC 22000']);

        self::assertSame('FSSC 22000', $certification['name']);
        self::assertTrue($certification['is_active']);
    }

    public function testNameIsRequired(): void
    {
        $error = $this->captureApiError(fn () => $this->certifications->create([]));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    public function testLogoMustReferenceARealMediaAsset(): void
    {
        $error = $this->captureApiError(fn () => $this->certifications->create([
            'name' => 'X', 'logo_id' => UlidHelper::generate(),
        ]));

        self::assertSame('logo_id', $error->details()[0]['field']);
    }

    public function testListingIncludesANewlyCreatedCertification(): void
    {
        $certification = $this->certifications->create(['name' => 'A Distinctive New Certification']);

        $names = array_column($this->certifications->list(), 'name');

        self::assertContains('A Distinctive New Certification', $names);
    }

    public function testDeletingActuallyRemovesTheRow(): void
    {
        $certification = $this->certifications->create(['name' => 'Temp']);

        $this->certifications->delete($certification['id']);

        $error = $this->captureApiError(fn () => $this->certifications->find($certification['id']));
        self::assertSame(ErrorCode::NOT_FOUND, $error->errorCode());
    }

    public function testReorderAppliesPositionsAcrossTheWholeList(): void
    {
        $a = $this->certifications->create(['name' => 'Reorder A']);
        $b = $this->certifications->create(['name' => 'Reorder B']);

        $this->certifications->reorder(['ids' => [$b['id'], $a['id']]]);

        self::assertSame(1, $this->certifications->find($b['id'])['sort_order']);
        self::assertSame(2, $this->certifications->find($a['id'])['sort_order']);
    }

    public function testReorderWithANonExistentIdIsRejected(): void
    {
        $error = $this->captureApiError(fn () => $this->certifications->reorder(['ids' => [UlidHelper::generate()]]));

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    // ─── public read (RTPP-67) ───────────────────────────────────────────

    public function testPublicListIncludesAnActiveCertificationButNotAnInactiveOne(): void
    {
        $this->certifications->create(['name' => 'Public Active Certification', 'is_active' => true]);
        $this->certifications->create(['name' => 'Public Inactive Certification', 'is_active' => false]);

        $names = array_column($this->certifications->publicList(), 'name');

        self::assertContains('Public Active Certification', $names);
        self::assertNotContains('Public Inactive Certification', $names);
    }

    public function testPublicViewShapesTheLogoAndCertificateUrl(): void
    {
        $logoId = $this->insertMediaAsset();
        $fileId = $this->insertMediaAsset();
        $certification = $this->certifications->create([
            'name' => 'Certification With Assets', 'logo_id' => $logoId, 'certificate_file_id' => $fileId,
        ]);

        $found = array_values(array_filter(
            $this->certifications->publicList(),
            static fn (array $row): bool => $row['id'] === $certification['id'],
        ))[0];

        self::assertArrayNotHasKey('logo_id', $found);
        self::assertArrayNotHasKey('certificate_file_id', $found);
        self::assertSame('https://example.test/img.jpg', $found['logo']['url']);
        self::assertSame('https://example.test/img.jpg', $found['certificate_url']);
    }

    private function insertMediaAsset(): string
    {
        $id = UlidHelper::generate();
        $this->db->prepare(
            'INSERT INTO media_assets (id, public_id, secure_url, type, created_at)
             VALUES (:id, :public_id, :url, \'IMAGE\', :now)'
        )->execute([
            ':id'        => $id,
            ':public_id' => 'test/' . bin2hex(random_bytes(6)),
            ':url'       => 'https://example.test/img.jpg',
            ':now'       => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ]);

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

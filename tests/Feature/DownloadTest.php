<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\DownloadRepository;
use Rajdhani\Services\DownloadService;

/**
 * Brochure and catalogue downloads (doc §8.7, §9.6, §9.9, §10.3; RTPP-32),
 * against a real database.
 */
final class DownloadTest extends DatabaseTestCase
{
    private DownloadService $downloads;
    private string $mediaId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->downloads = new DownloadService(new DownloadRepository($this->db));
        $this->mediaId = $this->insertMediaAsset();
    }

    public function testCreatingADownload(): void
    {
        $created = $this->downloads->create($this->validInput());

        self::assertSame('dealer_brochure', $created['key']);
        self::assertSame(0, $created['download_count']);
        self::assertFalse($created['requires_email']);
        self::assertNotNull($created['file']);
    }

    public function testKeyMustBeSlugSafe(): void
    {
        $error = $this->captureApiError(
            fn () => $this->downloads->create(['key' => 'Dealer Brochure!'] + $this->validInput())
        );

        self::assertSame('key', $error->details()[0]['field']);
    }

    public function testKeyMustBeUnique(): void
    {
        $this->downloads->create($this->validInput());

        $error = $this->captureApiError(fn () => $this->downloads->create($this->validInput()));

        self::assertSame(409, $error->status());
    }

    public function testFileIdMustReferenceARealMediaAsset(): void
    {
        $error = $this->captureApiError(
            fn () => $this->downloads->create(['file_id' => UlidHelper::generate()] + $this->validInput())
        );

        self::assertSame('file_id', $error->details()[0]['field']);
    }

    public function testResolvingReturnsTheUrlAndIncrementsTheCounter(): void
    {
        $this->downloads->create($this->validInput());

        $first = $this->downloads->resolve('dealer_brochure');
        self::assertSame('https://example.test/img.jpg', $first['url']);

        $this->downloads->resolve('dealer_brochure');

        $admin = $this->downloads->find($this->findIdByKey('dealer_brochure'));
        self::assertSame(2, $admin['download_count']);
    }

    public function testResolvingNeverRequiresAnEmailFirst(): void
    {
        // §19's open item was resolved 2026-09-13: open, no email required —
        // see DownloadService's class doc. requires_email=true is stored but
        // not enforced.
        $this->downloads->create(['requires_email' => true] + $this->validInput());

        $result = $this->downloads->resolve('dealer_brochure');

        self::assertSame('https://example.test/img.jpg', $result['url']);
    }

    public function testResolvingAnInactiveDownloadIs404(): void
    {
        $created = $this->downloads->create($this->validInput());
        $this->downloads->update($created['id'], ['is_active' => false]);

        $error = $this->captureApiError(fn () => $this->downloads->resolve('dealer_brochure'));

        self::assertSame(404, $error->status());
    }

    public function testResolvingAMadeUpKeyIs404(): void
    {
        $error = $this->captureApiError(fn () => $this->downloads->resolve('no-such-key'));

        self::assertSame(404, $error->status());
    }

    public function testDeletingIsIdempotent(): void
    {
        $created = $this->downloads->create($this->validInput());
        $this->downloads->delete($created['id']);
        $this->downloads->delete($created['id']);

        $error = $this->captureApiError(fn () => $this->downloads->find($created['id']));
        self::assertSame(404, $error->status());
    }

    private function validInput(): array
    {
        return [
            'title' => 'Dealer Brochure', 'key' => 'dealer_brochure',
            'description' => 'Our full product and pricing brochure.', 'file_id' => $this->mediaId,
        ];
    }

    private function findIdByKey(string $key): string
    {
        $statement = $this->db->prepare('SELECT id FROM downloads WHERE `key` = :key');
        $statement->execute([':key' => $key]);

        return (string) $statement->fetchColumn();
    }

    private function insertMediaAsset(): string
    {
        $id = UlidHelper::generate();
        $statement = $this->db->prepare(
            'INSERT INTO media_assets (id, public_id, secure_url, type, created_at)
             VALUES (:id, :public_id, :url, \'DOCUMENT\', :now)'
        );
        $statement->execute([
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

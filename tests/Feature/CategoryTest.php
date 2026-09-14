<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Feature;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\UlidHelper;
use Rajdhani\Repositories\CategoryRepository;
use Rajdhani\Services\CategoryService;

/**
 * Category CRUD and the public listing, against a real database (doc §8.5,
 * §9.4, §9.9; RTPP-18).
 *
 * Two of these tests exist because they failed the first time this module was
 * built: `testDeletingACategoryActuallyWorks()` and
 * `testAnExplicitDuplicateSlugOnCreateIsRejectedNotRenamed()` each caught a
 * real bug (a reused PDO placeholder, and a silent slug rename) before either
 * reached the API. They stay as regression tests, not just coverage.
 */
final class CategoryTest extends DatabaseTestCase
{
    private CategoryService $categories;
    private CategoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new CategoryRepository($this->db);
        $this->categories = new CategoryService($this->repository);
    }

    // ─── create ─────────────────────────────────────────────────────────────

    public function testCreatingACategoryDerivesASlugFromTheName(): void
    {
        $category = $this->categories->create(['name' => 'Herbal Infusions']);

        self::assertSame('Herbal Infusions', $category['name']);
        self::assertSame('herbal-infusions', $category['slug']);
        self::assertTrue($category['is_active']);
        self::assertSame(0, $category['sort_order']);
    }

    public function testAnExplicitSlugIsHonouredExactly(): void
    {
        $category = $this->categories->create(['name' => 'Herbal Infusions', 'slug' => 'herbal-tea']);

        self::assertSame('herbal-tea', $category['slug']);
    }

    public function testANameCollisionIsAutoSuffixedWhenNoSlugWasGiven(): void
    {
        $this->categories->create(['name' => 'Herbal Infusions']);
        $second = $this->categories->create(['name' => 'Herbal Infusions']);

        // No slug was requested, so a "-2" is the right outcome — the admin
        // never chose a specific slug to be surprised about.
        self::assertSame('herbal-infusions-2', $second['slug']);
    }

    /**
     * Regression: the first implementation ran an explicit slug through the
     * same auto-suffix path as a derived one, so `{"slug": "gold-blend"}`
     * silently became `gold-blend-2` instead of failing. An admin who typed a
     * specific slug must get exactly that slug or a clear rejection.
     */
    public function testAnExplicitDuplicateSlugOnCreateIsRejectedNotRenamed(): void
    {
        $this->categories->create(['name' => 'First', 'slug' => 'shared-slug']);

        $error = $this->captureApiError(
            fn () => $this->categories->create(['name' => 'Second', 'slug' => 'shared-slug'])
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
        self::assertSame(409, $error->status());
        self::assertSame(1, $this->countBySlug('shared-slug'), 'The second request must not have inserted a row');
    }

    /**
     * The database is the actual guarantee, not the pre-check — proved by
     * inserting past the service, directly at the row the pre-check would have
     * looked at, so the pre-check cannot have been what stopped it.
     */
    public function testTheUniqueConstraintItselfRejectsADuplicateSlug(): void
    {
        $this->insertCategoryDirectly('taken-slug');

        $this->expectException(\PDOException::class);
        $this->insertCategoryDirectly('taken-slug');
    }

    public function testANameIsRequired(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->categories->create(['name' => '']);
    }

    public function testAnInvalidImageIdIsRejectedBeforeReachingTheDatabase(): void
    {
        $error = $this->captureApiError(
            fn () => $this->categories->create(['name' => 'X', 'image_id' => 'not-a-ulid'])
        );

        self::assertSame(ErrorCode::VALIDATION_ERROR, $error->errorCode());
    }

    // ─── read ───────────────────────────────────────────────────────────────

    public function testFindingAMissingCategoryIs404(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::NOT_FOUND->status());
        $this->categories->find('01ZZZZZZZZZZZZZZZZZZZZZZZZ');
    }

    public function testPaginationFiltersBySearchTerm(): void
    {
        $this->categories->create(['name' => 'Green Rooibos']);
        $this->categories->create(['name' => 'Black Assam']);

        $result = $this->categories->paginate(['search' => 'rooibos']);

        self::assertCount(1, $result['data']);
        self::assertSame('Green Rooibos', $result['data'][0]['name']);
    }

    // ─── update ─────────────────────────────────────────────────────────────

    public function testUpdatingOnlySendsFieldsThatWereActuallySent(): void
    {
        $created = $this->categories->create(['name' => 'Original', 'icon_name' => 'leaf']);

        $updated = $this->categories->update($created['id'], ['name' => 'Renamed']);

        self::assertSame('Renamed', $updated['name']);
        self::assertSame('leaf', $updated['icon_name'], 'A field not sent must not be cleared');
    }

    public function testUpdatingToAnExplicitDuplicateSlugIsRejected(): void
    {
        $this->categories->create(['name' => 'First', 'slug' => 'first-slug']);
        $second = $this->categories->create(['name' => 'Second', 'slug' => 'second-slug']);

        $error = $this->captureApiError(
            fn () => $this->categories->update($second['id'], ['slug' => 'first-slug'])
        );

        self::assertSame(ErrorCode::CONFLICT, $error->errorCode());
    }

    /** A category keeping its own slug on update must not be flagged as colliding with itself. */
    public function testUpdatingACategoryToItsOwnSlugIsNotAConflict(): void
    {
        $created = $this->categories->create(['name' => 'Steady', 'slug' => 'steady-slug']);

        $updated = $this->categories->update($created['id'], ['slug' => 'steady-slug', 'description' => 'still here']);

        self::assertSame('steady-slug', $updated['slug']);
    }

    public function testSendingNothingEditableOnUpdateIsRejected(): void
    {
        $created = $this->categories->create(['name' => 'X']);

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->categories->update($created['id'], []);
    }

    public function testUpdatingAMissingCategoryIs404(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::NOT_FOUND->status());
        $this->categories->update('01ZZZZZZZZZZZZZZZZZZZZZZZZ', ['name' => 'X']);
    }

    // ─── delete ─────────────────────────────────────────────────────────────

    /**
     * Regression: `CategoryRepository::softDelete()` originally bound `:now`
     * to two placeholders in one statement. `PDO::ATTR_EMULATE_PREPARES` is
     * false (config/database.php), so a real prepared statement cannot reuse a
     * named placeholder — every delete failed with "Invalid parameter number",
     * a 500 the API caller would have seen with no useful explanation.
     */
    public function testDeletingACategoryActuallyWorks(): void
    {
        $created = $this->categories->create(['name' => 'To Delete']);

        $this->categories->delete($created['id']);

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::NOT_FOUND->status());
        $this->categories->find($created['id']);
    }

    public function testDeletingIsIdempotent(): void
    {
        $created = $this->categories->create(['name' => 'To Delete']);

        $this->categories->delete($created['id']);
        $this->categories->delete($created['id']); // must not throw

        self::assertTrue(true);
    }

    public function testDeletingAnUnknownIdDoesNotThrow(): void
    {
        $this->categories->delete('01ZZZZZZZZZZZZZZZZZZZZZZZZ');

        self::assertTrue(true);
    }

    /**
     * The repository doc's own reasoning: a category with products must never
     * be hard-deleted, because `products.category_id` has a foreign key into
     * this table. Soft delete leaves that reference completely intact.
     */
    public function testDeletingACategoryWithProductsLeavesTheForeignKeyIntact(): void
    {
        $category = $this->categories->create(['name' => 'Has Products']);
        $this->insertMinimalProduct($category['id']);

        $this->categories->delete($category['id']);

        $stillReferenced = (int) $this->db
            ->query('SELECT COUNT(*) FROM products WHERE category_id = ' . $this->db->quote($category['id']))
            ->fetchColumn();

        self::assertSame(1, $stillReferenced);
    }

    // ─── reorder ────────────────────────────────────────────────────────────

    public function testReorderingPersistsTheNewSortOrder(): void
    {
        $first = $this->categories->create(['name' => 'A']);
        $second = $this->categories->create(['name' => 'B']);

        $this->categories->reorder(['ids' => [$second['id'], $first['id']]]);

        self::assertSame(1, $this->sortOrderOf($second['id']));
        self::assertSame(2, $this->sortOrderOf($first['id']));
    }

    /** The DoD in RTPP-18: reordering must show up in the public payload on the next request. */
    public function testReorderingIsReflectedInThePublicPayload(): void
    {
        $first = $this->categories->create(['name' => 'A']);
        $second = $this->categories->create(['name' => 'B']);

        $before = array_column($this->categories->publicList(), 'slug');
        self::assertLessThan(
            array_search($second['slug'], $before, true),
            array_search($first['slug'], $before, true),
        );

        $this->categories->reorder(['ids' => [$second['id'], $first['id']]]);

        $after = array_column($this->categories->publicList(), 'slug');
        self::assertLessThan(
            array_search($first['slug'], $after, true),
            array_search($second['slug'], $after, true),
        );
    }

    public function testReorderingRejectsAMalformedId(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->categories->reorder(['ids' => ['not-a-ulid']]);
    }

    public function testReorderingRejectsARepeatedId(): void
    {
        $created = $this->categories->create(['name' => 'A']);

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->categories->reorder(['ids' => [$created['id'], $created['id']]]);
    }

    /**
     * A stale id (a category deleted in another tab) must not silently drop
     * the whole batch — the entries that do exist already have their new
     * position by the time the missing one is discovered.
     */
    public function testReorderingWithAMissingIdStillAppliesTheValidEntries(): void
    {
        $first = $this->categories->create(['name' => 'A']);
        $ghost = '01ZZZZZZZZZZZZZZZZZZZZZZZZ';

        $this->captureApiError(fn () => $this->categories->reorder(['ids' => [$first['id'], $ghost]]));

        self::assertSame(1, $this->sortOrderOf($first['id']));
    }

    public function testReorderingRequiresANonEmptyList(): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionCode(ErrorCode::VALIDATION_ERROR->status());
        $this->categories->reorder(['ids' => []]);
    }

    // ─── public listing ─────────────────────────────────────────────────────

    public function testPublicListExcludesInactiveAndDeletedCategories(): void
    {
        $active = $this->categories->create(['name' => 'Visible']);
        $inactive = $this->categories->create(['name' => 'Hidden', 'is_active' => false]);
        $deleted = $this->categories->create(['name' => 'Gone']);
        $this->categories->delete($deleted['id']);

        $slugs = array_column($this->categories->publicList(), 'slug');

        self::assertContains($active['slug'], $slugs);
        self::assertNotContains($inactive['slug'], $slugs);
        self::assertNotContains($deleted['slug'], $slugs);
    }

    public function testPublicListCountsOnlyPublishedNonDeletedProducts(): void
    {
        $category = $this->categories->create(['name' => 'Counted']);
        $this->insertMinimalProduct($category['id'], status: 'PUBLISHED');
        $this->insertMinimalProduct($category['id'], status: 'PUBLISHED');
        $this->insertMinimalProduct($category['id'], status: 'DRAFT');

        $row = $this->findPublic($category['slug']);

        self::assertSame(2, $row['product_count']);
    }

    public function testACategoryWithNoProductsShowsAZeroCount(): void
    {
        $category = $this->categories->create(['name' => 'Empty']);

        self::assertSame(0, $this->findPublic($category['slug'])['product_count']);
    }

    public function testAPublicCategoryWithNoImageReturnsNullNotAPartialObject(): void
    {
        $category = $this->categories->create(['name' => 'No Image']);

        self::assertNull($this->findPublic($category['slug'])['image']);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function sortOrderOf(string $id): int
    {
        $statement = $this->db->prepare('SELECT sort_order FROM categories WHERE id = :id');
        $statement->execute([':id' => $id]);

        return (int) $statement->fetchColumn();
    }

    private function countBySlug(string $slug): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM categories WHERE slug = :slug');
        $statement->execute([':slug' => $slug]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string,mixed> */
    private function findPublic(string $slug): array
    {
        foreach ($this->categories->publicList() as $row) {
            if ($row['slug'] === $slug) {
                return $row;
            }
        }

        self::fail("No public category with slug '{$slug}'");
    }

    private function insertCategoryDirectly(string $slug): void
    {
        $now = $this->now();
        $statement = $this->db->prepare(
            'INSERT INTO categories (id, name, slug, sort_order, is_active, created_at, updated_at)
             VALUES (:id, :name, :slug, 0, 1, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id'         => UlidHelper::generate(),
            ':name'       => 'Direct Insert',
            ':slug'       => $slug,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
    }

    private function insertMinimalProduct(string $categoryId, string $status = 'PUBLISHED'): void
    {
        $now = $this->now();
        $statement = $this->db->prepare(
            'INSERT INTO products (id, category_id, name, slug, status, created_at, updated_at)
             VALUES (:id, :category_id, :name, :slug, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            ':id'          => UlidHelper::generate(),
            ':category_id' => $categoryId,
            ':name'        => 'Test Product ' . bin2hex(random_bytes(3)),
            ':slug'        => 'test-product-' . bin2hex(random_bytes(6)),
            ':status'      => $status,
            ':created_at'  => $now,
            ':updated_at'  => $now,
        ]);
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
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

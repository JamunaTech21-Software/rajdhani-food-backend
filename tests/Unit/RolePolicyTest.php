<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rajdhani\Auth\AccessLevel;
use Rajdhani\Auth\Capability;
use Rajdhani\Auth\Role;
use Rajdhani\Auth\RolePolicy;

/**
 * The §7.3 matrix, transcribed independently from the document.
 *
 * The expectations below are written out by hand rather than derived from
 * RolePolicy, which is the entire point: a test that read the same constant it
 * is testing would pass no matter what that constant said. This is the second
 * copy, and a disagreement between the two is a disagreement with the document.
 */
final class RolePolicyTest extends TestCase
{
    /**
     * Every cell of §7.3. 16 capabilities × 3 roles = 48 assertions of record.
     *
     * @return array<string,array{Role,Capability,AccessLevel}>
     */
    public static function matrix(): array
    {
        $w = AccessLevel::WRITE;
        $r = AccessLevel::READ;
        $o = AccessLevel::OWN;
        $n = AccessLevel::NONE;

        $rows = [
            //                                   SUPER_ADMIN  EDITOR  SALES
            Capability::DASHBOARD->value           => [$r, $r, $r],
            Capability::PRODUCTS->value            => [$w, $w, $r],
            Capability::CONTENT->value             => [$w, $w, $n],
            Capability::GALLERY->value             => [$w, $w, $n],
            Capability::NEWS->value                => [$w, $w, $n],
            Capability::MARKETING->value           => [$w, $w, $n],
            Capability::REVIEWS->value             => [$w, $w, $n],
            Capability::DOWNLOADS->value           => [$w, $w, $r],
            Capability::ENQUIRIES->value           => [$w, $r, $w],
            Capability::DEALER_APPLICATIONS->value => [$w, $r, $w],
            Capability::CONTACT_MESSAGES->value    => [$w, $r, $w],
            Capability::NEWSLETTER->value          => [$w, $n, $w],
            Capability::SETTINGS->value            => [$w, $n, $n],
            Capability::ADMIN_USERS->value         => [$w, $n, $n],
            Capability::AUDIT_LOG->value           => [$r, $n, $n],
            Capability::MEDIA->value               => [$w, $o, $n],
        ];

        $roles = [Role::SUPER_ADMIN, Role::EDITOR, Role::SALES];
        $cases = [];

        foreach ($rows as $capabilityValue => $levels) {
            $capability = Capability::from($capabilityValue);

            foreach ($roles as $index => $role) {
                $cases["{$role->value} / {$capabilityValue}"] = [$role, $capability, $levels[$index]];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function testEveryCellOfTheMatrix(Role $role, Capability $capability, AccessLevel $expected): void
    {
        self::assertSame(
            $expected,
            RolePolicy::levelFor($role, $capability),
            "§7.3: {$role->value} / {$capability->value} should be {$expected->label()}",
        );
    }

    /** No capability may be left undecided — the provider above must cover them all. */
    public function testTheMatrixCoversEveryCapability(): void
    {
        $covered = [];

        foreach (array_keys(self::matrix()) as $key) {
            $covered[explode(' / ', $key)[1]] = true;
        }

        foreach (Capability::cases() as $capability) {
            self::assertArrayHasKey($capability->value, $covered, "{$capability->value} is untested");
        }
    }

    // ─── the ordering that makes "at least" work ────────────────────────────

    public function testWriteSatisfiesEveryLowerRequirement(): void
    {
        foreach ([AccessLevel::NONE, AccessLevel::READ, AccessLevel::OWN, AccessLevel::WRITE] as $required) {
            self::assertTrue(AccessLevel::WRITE->satisfies($required));
        }
    }

    public function testReadDoesNotSatisfyWrite(): void
    {
        self::assertFalse(AccessLevel::READ->satisfies(AccessLevel::WRITE));
        self::assertFalse(AccessLevel::READ->satisfies(AccessLevel::OWN));
        self::assertFalse(AccessLevel::NONE->satisfies(AccessLevel::READ));
    }

    public function testOwnSatisfiesReadButNotWrite(): void
    {
        self::assertTrue(AccessLevel::OWN->satisfies(AccessLevel::READ));
        self::assertTrue(AccessLevel::OWN->satisfies(AccessLevel::OWN));
        self::assertFalse(AccessLevel::OWN->satisfies(AccessLevel::WRITE));
    }

    // ─── the specific distinctions the issue calls out ──────────────────────

    public function testEditorsReadLeadsButDoNotManageThem(): void
    {
        foreach ([Capability::ENQUIRIES, Capability::DEALER_APPLICATIONS, Capability::CONTACT_MESSAGES] as $c) {
            self::assertTrue(RolePolicy::allows(Role::EDITOR, $c, AccessLevel::READ));
            self::assertFalse(RolePolicy::allows(Role::EDITOR, $c, AccessLevel::WRITE));
        }
    }

    public function testSalesReadsProductsButDoesNotEditThem(): void
    {
        self::assertTrue(RolePolicy::allows(Role::SALES, Capability::PRODUCTS, AccessLevel::READ));
        self::assertFalse(RolePolicy::allows(Role::SALES, Capability::PRODUCTS, AccessLevel::WRITE));
    }

    /** "Super-Admin-only routes: site profile, settings, admin users, audit log." */
    #[DataProvider('superAdminOnlyCapabilities')]
    public function testSuperAdminOnlyCapabilitiesAreClosedToEveryoneElse(Capability $capability): void
    {
        self::assertNotSame(AccessLevel::NONE, RolePolicy::levelFor(Role::SUPER_ADMIN, $capability));
        self::assertSame(AccessLevel::NONE, RolePolicy::levelFor(Role::EDITOR, $capability));
        self::assertSame(AccessLevel::NONE, RolePolicy::levelFor(Role::SALES, $capability));
    }

    /** @return array<string,array{Capability}> */
    public static function superAdminOnlyCapabilities(): array
    {
        return [
            'settings'    => [Capability::SETTINGS],
            'admin users' => [Capability::ADMIN_USERS],
            'audit log'   => [Capability::AUDIT_LOG],
        ];
    }

    /** Nobody edits the audit log — it would stop being evidence. */
    public function testTheAuditLogIsReadOnlyEvenForASuperAdmin(): void
    {
        self::assertFalse(RolePolicy::allows(Role::SUPER_ADMIN, Capability::AUDIT_LOG, AccessLevel::WRITE));
    }

    // ─── row scope, the one OWN cell ────────────────────────────────────────

    public function testMediaScopeIsAllForSuperAdminAndOwnForEditor(): void
    {
        self::assertSame('all', RolePolicy::scopeFor(Role::SUPER_ADMIN, Capability::MEDIA));
        self::assertSame('own', RolePolicy::scopeFor(Role::EDITOR, Capability::MEDIA));
        self::assertSame('none', RolePolicy::scopeFor(Role::SALES, Capability::MEDIA));
    }

    // ─── defaults ───────────────────────────────────────────────────────────

    public function testAnUnknownRoleStringResolvesToNull(): void
    {
        self::assertNull(Role::tryFromClaim('ROOT'));
        self::assertNull(Role::tryFromClaim(null));
        self::assertNull(Role::tryFromClaim(['SUPER_ADMIN']));
        self::assertSame(Role::SUPER_ADMIN, Role::tryFromClaim('SUPER_ADMIN'));
    }

    public function testThePermissionsPayloadListsEveryCapability(): void
    {
        $permissions = RolePolicy::permissionsFor(Role::EDITOR);

        self::assertCount(count(Capability::cases()), $permissions);
        self::assertSame('write', $permissions[Capability::PRODUCTS->value]);
        self::assertSame('read', $permissions[Capability::ENQUIRIES->value]);
        self::assertSame('none', $permissions[Capability::SETTINGS->value]);
        self::assertSame('own', $permissions[Capability::MEDIA->value]);
    }
}

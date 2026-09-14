<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rajdhani\Auth\AccessLevel;
use Rajdhani\Auth\Capability;
use Rajdhani\Auth\Role;
use Rajdhani\Auth\RolePolicy;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Helpers\JwtHelper;
use Rajdhani\Http\Request;
use Rajdhani\Http\Router;
use Rajdhani\Kernel;
use Rajdhani\Middleware\RequireAdmin;
use Rajdhani\Middleware\RequireRole;
use Rajdhani\Support\Env;

/**
 * §7.3 enforced end to end: a real signed token, the real middleware chain, a
 * real route.
 *
 * RolePolicyTest checks the table. This checks that the table is actually
 * *applied* — the two are different failures, and §18.7 is explicit that the UI
 * hiding a button proves nothing. Every request here goes through
 * Kernel::pipeline(), so routing, RequireAdmin's JWT decode and RequireRole's
 * matrix lookup all run exactly as they do in production.
 *
 * The routes are mounted by this test rather than taken from routes/admin.php
 * because the admin endpoints themselves arrive with Phase 2. What is under
 * test is the gate, and the gate is complete.
 */
final class RequireRoleTest extends TestCase
{
    protected function setUp(): void
    {
        Env::load(TEST_ENV_PATH);
    }

    /**
     * Every capability at every access level, for every role: 16 × 3 × 3.
     *
     * @return array<string,array{Role,Capability,AccessLevel}>
     */
    public static function everyCell(): array
    {
        $cases = [];

        foreach ([Role::SUPER_ADMIN, Role::EDITOR, Role::SALES] as $role) {
            foreach (Capability::cases() as $capability) {
                foreach ([AccessLevel::READ, AccessLevel::OWN, AccessLevel::WRITE] as $required) {
                    $key = "{$role->value} needs {$required->label()} on {$capability->value}";
                    $cases[$key] = [$role, $capability, $required];
                }
            }
        }

        return $cases;
    }

    /**
     * The route is reachable exactly when the matrix says so, and returns a
     * §9.1 FORBIDDEN envelope when it does not.
     */
    #[DataProvider('everyCell')]
    public function testTheGateAgreesWithTheMatrix(Role $role, Capability $capability, AccessLevel $required): void
    {
        $expected = RolePolicy::allows($role, $capability, $required);
        $runner = $this->pipelineFor($capability, $required);
        $request = $this->requestAs($role);

        if ($expected) {
            self::assertSame(
                ['reached' => true],
                $runner($request),
                "{$role->value} should reach {$required->label()} on {$capability->value}",
            );

            return;
        }

        try {
            $runner($request);
            self::fail("{$role->value} should NOT reach {$required->label()} on {$capability->value}");
        } catch (ApiError $e) {
            self::assertSame(ErrorCode::FORBIDDEN, $e->errorCode());
            self::assertSame(403, $e->status());
        }
    }

    /** The issue's own wording: "An Editor token cannot reach a Super-Admin route". */
    #[DataProvider('superAdminOnlyRoutes')]
    public function testAnEditorAndSalesTokenCannotReachASuperAdminRoute(Capability $capability): void
    {
        $runner = $this->pipelineFor($capability, AccessLevel::READ);

        foreach ([Role::EDITOR, Role::SALES] as $role) {
            try {
                $runner($this->requestAs($role));
                self::fail("{$role->value} reached {$capability->value}");
            } catch (ApiError $e) {
                self::assertSame(ErrorCode::FORBIDDEN, $e->errorCode());
            }
        }

        self::assertSame(['reached' => true], $runner($this->requestAs(Role::SUPER_ADMIN)));
    }

    /** @return array<string,array{Capability}> */
    public static function superAdminOnlyRoutes(): array
    {
        return [
            'site settings'         => [Capability::SETTINGS],
            'admin user management' => [Capability::ADMIN_USERS],
            'audit log'             => [Capability::AUDIT_LOG],
        ];
    }

    /**
     * The OWN cell. Both roles get in; what differs is the row scope the
     * middleware records for the service to act on.
     */
    public function testMediaDeleteRecordsTheRowScope(): void
    {
        $scopes = [];
        $router = new Router();
        $router->post(
            '/admin/media/delete',
            static function (Request $r) use (&$scopes): array {
                $scopes[] = $r->attribute('row_scope');

                return ['reached' => true];
            },
            [RequireAdmin::class, RequireRole::own(Capability::MEDIA)],
        );

        $runner = Kernel::pipeline($router);
        $runner($this->requestAs(Role::SUPER_ADMIN, '/admin/media/delete', 'POST'));
        $runner($this->requestAs(Role::EDITOR, '/admin/media/delete', 'POST'));

        self::assertSame(['all', 'own'], $scopes);
    }

    /** An Editor may not delete another admin's uploads, so WRITE is refused. */
    public function testAnEditorCannotBeGrantedUnscopedMediaDelete(): void
    {
        $runner = $this->pipelineFor(Capability::MEDIA, AccessLevel::WRITE);

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(403);
        $runner($this->requestAs(Role::EDITOR));
    }

    // ─── the authentication boundary ────────────────────────────────────────

    public function testNoTokenIsRejectedBeforeTheRoleIsEverConsidered(): void
    {
        $runner = $this->pipelineFor(Capability::DASHBOARD, AccessLevel::READ);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/admin/gate';
        unset($_SERVER['HTTP_AUTHORIZATION']);

        try {
            $runner(Request::capture());
            self::fail('An unauthenticated request should not reach the handler');
        } catch (ApiError $e) {
            // 401, not 403: the caller is not "not allowed", they are unknown.
            self::assertSame(ErrorCode::UNAUTHENTICATED, $e->errorCode());
        }
    }

    /**
     * A token carrying a role this build does not know is denied rather than
     * defaulted — guessing what a retired role meant is how escalation ships.
     */
    public function testAnUnrecognisedRoleIsDenied(): void
    {
        $runner = $this->pipelineFor(Capability::DASHBOARD, AccessLevel::READ);

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(403);
        $runner($this->requestWithRoleClaim('ROOT'));
    }

    public function testATokenWithNoRoleClaimIsDenied(): void
    {
        $runner = $this->pipelineFor(Capability::DASHBOARD, AccessLevel::READ);

        $this->expectException(ApiError::class);
        $this->expectExceptionCode(403);
        $runner($this->requestWithRoleClaim(null));
    }

    /** A customer token must not satisfy an admin route, whatever role it claims. */
    public function testACustomerTokenClaimingSuperAdminIsRejected(): void
    {
        $runner = $this->pipelineFor(Capability::SETTINGS, AccessLevel::WRITE);

        $token = JwtHelper::encode(
            JwtHelper::claims('customer-1', 'customer', 900, ['role' => 'SUPER_ADMIN']),
            (string) config('auth.jwt.access_secret'),
        );

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/v1/admin/gate';
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$token}";

        try {
            $runner(Request::capture());
            self::fail('A customer token reached an admin route');
        } catch (ApiError $e) {
            // Stopped by the audience check in RequireAdmin, before RequireRole
            // is ever asked — which is why this is 401 and not 403.
            self::assertSame(ErrorCode::UNAUTHENTICATED, $e->errorCode());
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return callable(Request):mixed */
    private function pipelineFor(Capability $capability, AccessLevel $required): callable
    {
        $gate = match ($required) {
            AccessLevel::WRITE => RequireRole::write($capability),
            AccessLevel::OWN   => RequireRole::own($capability),
            default            => RequireRole::read($capability),
        };

        $router = new Router();
        $router->get(
            '/admin/gate',
            static fn (Request $r): array => ['reached' => true],
            [RequireAdmin::class, $gate],
        );

        return Kernel::pipeline($router);
    }

    private function requestAs(Role $role, string $path = '/admin/gate', string $method = 'GET'): Request
    {
        return $this->requestWithRoleClaim($role->value, $path, $method);
    }

    private function requestWithRoleClaim(
        ?string $role,
        string $path = '/admin/gate',
        string $method = 'GET',
    ): Request {
        $extra = $role === null ? [] : ['role' => $role];

        $token = JwtHelper::encode(
            JwtHelper::claims('01ADMINIDFORTESTS000000000', 'admin', 900, $extra),
            (string) config('auth.jwt.access_secret'),
        );

        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = "/api/v1{$path}";
        $_SERVER['REMOTE_ADDR'] = '203.0.113.30';
        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer {$token}";
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];

        return Request::capture();
    }
}

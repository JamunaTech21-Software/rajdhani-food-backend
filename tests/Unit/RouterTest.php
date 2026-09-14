<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Http\Router;

final class RouterTest extends TestCase
{
    public function testMatchesALiteralPath(): void
    {
        $router = new Router();
        $router->get('/health', static fn (): string => 'ok');

        $matched = $router->match('GET', '/health');

        self::assertSame([], $matched['params']);
        self::assertSame('ok', ($matched['handler'])());
    }

    public function testExtractsNamedParameters(): void
    {
        $router = new Router();
        $router->get('/public/products/:slug/reviews', static fn (): string => 'reviews');

        $matched = $router->match('GET', '/public/products/premium-tea/reviews');

        self::assertSame(['slug' => 'premium-tea'], $matched['params']);
    }

    public function testGroupsComposePrefixAndMiddleware(): void
    {
        $router = new Router();
        $router->group('/admin', ['AuthAdmin'], static function (Router $r): void {
            $r->get('/products', static fn (): string => 'list', ['RequireRole']);
        });

        $matched = $router->match('GET', '/admin/products');

        self::assertSame(['AuthAdmin', 'RequireRole'], $matched['middleware']);
    }

    public function testGroupMiddlewareDoesNotLeakToLaterRoutes(): void
    {
        $router = new Router();
        $router->group('/admin', ['AuthAdmin'], static function (Router $r): void {
            $r->get('/products', static fn (): string => 'list');
        });
        $router->get('/health', static fn (): string => 'ok');

        self::assertSame([], $router->match('GET', '/health')['middleware']);
    }

    public function testUnknownPathThrowsNotFound(): void
    {
        $router = new Router();
        $router->get('/health', static fn (): string => 'ok');

        $this->expectException(ApiError::class);
        $router->match('GET', '/nope');
    }

    public function testKnownPathWithTheWrongMethodSaysSo(): void
    {
        // The status stays inside the closed set of codes in section 9.1, but the
        // message distinguishes "wrong verb" from "no such path", which is the
        // difference between a five-minute fix and an afternoon.
        $router = new Router();
        $router->get('/health', static fn (): string => 'ok');

        try {
            $router->match('POST', '/health');
            self::fail('Expected an ApiError');
        } catch (ApiError $e) {
            self::assertSame(ErrorCode::NOT_FOUND, $e->errorCode());
            self::assertStringContainsString('POST', $e->getMessage());
        }
    }

    public function testDoesNotMatchOnSegmentCountAlone(): void
    {
        $router = new Router();
        $router->get('/public/products/:slug', static fn (): string => 'detail');

        $this->expectException(ApiError::class);
        $router->match('GET', '/public/categories/tea');
    }

    public function testTableListsEveryRegisteredRoute(): void
    {
        $router = new Router();
        $router->get('/health', static fn (): string => 'ok');
        $router->post('/public/enquiries', static fn (): string => 'created');

        self::assertSame(
            [
                ['method' => 'GET', 'path' => '/health'],
                ['method' => 'POST', 'path' => '/public/enquiries'],
            ],
            $router->table(),
        );
    }
}

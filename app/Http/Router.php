<?php

declare(strict_types=1);

namespace Rajdhani\Http;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Middleware\Middleware;

/**
 * A small routing table with a per-route middleware pipeline.
 *
 * No framework, so this is the whole mechanism. It supports literal segments and
 * `:name` parameters, which is everything section 9 asks for — there are no
 * optional segments or regex constraints in the route map, so there is no
 * machinery here for them.
 *
 * Middleware order is fixed by registration and runs outside-in, then the
 * handler, then unwinds. `AuditLog` relies on that unwinding to record what a
 * mutation actually did.
 */
final class Router
{
    /** @var array<int,array{method:string,segments:string[],handler:callable,middleware:list<class-string<Middleware>|Middleware>}> */
    private array $routes = [];

    /** @var list<class-string<Middleware>|Middleware> */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    /**
     * A route handler that instantiates its controller when the route is hit.
     *
     * `[Controller::class, 'method']` is only a callable when the method is
     * static, and static controllers cannot be given a test double. This keeps
     * handlers lazy — nothing is constructed for routes the request did not
     * match — without making every route file write out a closure.
     *
     * @param class-string $controller
     *
     * @return callable(Request):mixed
     */
    public static function to(string $controller, string $method): callable
    {
        return static function (Request $request) use ($controller, $method): mixed {
            /** @var object $instance */
            $instance = new $controller();

            /** @var callable(Request):mixed $action */
            $action = [$instance, $method];

            return $action($request);
        };
    }

    /**
     * @param list<class-string<Middleware>|Middleware> $middleware
     * @param callable(self):void                       $register
     */
    public function group(string $prefix, array $middleware, callable $register): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix .= $prefix;
        $this->groupMiddleware = [...$this->groupMiddleware, ...$middleware];

        $register($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /** @param list<class-string<Middleware>|Middleware> $middleware */
    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    /** @param list<class-string<Middleware>|Middleware> $middleware */
    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param list<class-string<Middleware>|Middleware> $middleware */
    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    /** @param list<class-string<Middleware>|Middleware> $middleware */
    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    /** @param list<class-string<Middleware>|Middleware> $middleware */
    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    /** @param list<class-string<Middleware>|Middleware> $middleware */
    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $full = $this->groupPrefix . $path;

        $this->routes[] = [
            'method'     => $method,
            'segments'   => $this->split($full),
            'handler'    => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    /** @return string[] */
    private function split(string $path): array
    {
        $trimmed = trim($path, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /**
     * @return array{handler:callable,middleware:list<class-string<Middleware>|Middleware>,params:array<string,string>}
     *
     * @throws ApiError 404 when no path matches, 405 when only the method differs
     */
    public function match(string $method, string $path): array
    {
        $segments = $this->split($path);
        $pathMatchedOtherMethod = false;

        foreach ($this->routes as $route) {
            if (count($route['segments']) !== count($segments)) {
                continue;
            }

            $params = [];
            $matches = true;

            foreach ($route['segments'] as $i => $segment) {
                if (str_starts_with($segment, ':')) {
                    $params[substr($segment, 1)] = $segments[$i];

                    continue;
                }

                if ($segment !== $segments[$i]) {
                    $matches = false;

                    break;
                }
            }

            if (!$matches) {
                continue;
            }

            if ($route['method'] !== $method) {
                $pathMatchedOtherMethod = true;

                continue;
            }

            return ['handler' => $route['handler'], 'middleware' => $route['middleware'], 'params' => $params];
        }

        // Distinguishing 405 from 404 is worth the extra pass: it tells a client
        // the route exists and they used the wrong verb, which is a far more
        // actionable error than "not found".
        if ($pathMatchedOtherMethod) {
            throw new ApiError(
                \Rajdhani\Helpers\ErrorCode::NOT_FOUND,
                sprintf('The %s method is not supported for this route', $method),
            );
        }

        throw ApiError::notFound('No route matches this path');
    }

    /** @return array<int,array{method:string,path:string}> Used by the OpenAPI drift check (RTPP-16). */
    public function table(): array
    {
        $out = [];

        foreach ($this->routes as $route) {
            $out[] = ['method' => $route['method'], 'path' => '/' . implode('/', $route['segments'])];
        }

        return $out;
    }
}

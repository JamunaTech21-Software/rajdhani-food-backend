<?php

declare(strict_types=1);

namespace Rajdhani;

use Psr\Log\LoggerInterface;
use Rajdhani\Helpers\ApiError;
use Rajdhani\Helpers\ApiResponse;
use Rajdhani\Helpers\ErrorCode;
use Rajdhani\Http\Request;
use Rajdhani\Http\Router;
use Rajdhani\Middleware\Middleware;
use Rajdhani\Support\Env;
use Rajdhani\Support\Logger;
use Throwable;

/**
 * Boots the application and turns one request into one response.
 *
 * The contract that matters: **nothing leaves this class except a section 9.1
 * envelope.** Any throwable that is not an ApiError is a bug — it gets logged
 * with its stack trace and answered with a generic INTERNAL_ERROR, so an
 * unexpected exception cannot leak a file path, a query, or a credential.
 */
final class Kernel
{
    private static ?LoggerInterface $logger = null;

    public static function boot(string $basePath): void
    {
        Env::load($basePath . '/.env');

        // Errors become exceptions, so one handler covers everything. Without
        // this a PHP notice would print into the JSON body and corrupt it.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        $debug = (bool) config('app.debug', false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        date_default_timezone_set((string) config('app.timezone', 'UTC'));

        // A fatal that bypasses the exception handler still has to produce an
        // envelope rather than a blank 200 with a warning in the body.
        register_shutdown_function(static function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            self::logger()->critical('Fatal error', [
                'message' => $error['message'],
                'file'    => $error['file'],
                'line'    => $error['line'],
            ]);

            if (!headers_sent()) {
                ApiResponse::error(ErrorCode::INTERNAL_ERROR, 'Something went wrong');
            }
        });
    }

    /**
     * Compose routing and middleware into one callable.
     *
     * Separated from handle() so it can be driven from a test with a Request
     * built by hand: handle() is `never`-returning by design — it writes a
     * response and exits — which makes the composition itself untestable while
     * it is buried inside. Authorisation is composition, so that matters.
     *
     * Routing happens *inside* the global middleware chain, not before it. A
     * CORS preflight arrives as OPTIONS on a path that has no OPTIONS route
     * registered, so routing first would 404 the request before Cors ever saw
     * it — and the browser would report a CORS failure for a valid endpoint.
     *
     * @param array<int,class-string<Middleware>|Middleware> $globalMiddleware
     *
     * @return callable(Request):mixed
     */
    public static function pipeline(Router $router, array $globalMiddleware = []): callable
    {
        $dispatch = static function (Request $req) use ($router): mixed {
            $matched = $router->match($req->method, $req->path);

            foreach ($matched['params'] as $name => $value) {
                $req->setAttribute($name, $value);
            }

            $runner = array_reduce(
                array_reverse($matched['middleware']),
                static function (callable $next, string|Middleware $middleware): callable {
                    return static fn (Request $r): mixed => self::resolve($middleware)->handle($r, $next);
                },
                static fn (Request $r): mixed => ($matched['handler'])($r),
            );

            return $runner($req);
        };

        return array_reduce(
            array_reverse($globalMiddleware),
            static function (callable $next, string|Middleware $middleware): callable {
                return static fn (Request $req): mixed => self::resolve($middleware)->handle($req, $next);
            },
            $dispatch,
        );
    }

    /**
     * Middleware is registered either by class name or, when it needs
     * configuring, as an already-built instance — RequireRole is the latter,
     * because the capability it guards is part of the route definition and the
     * router has nowhere to put constructor arguments.
     *
     * @param class-string<Middleware>|Middleware $middleware
     */
    private static function resolve(string|Middleware $middleware): Middleware
    {
        return $middleware instanceof Middleware ? $middleware : new $middleware();
    }

    public static function logger(): LoggerInterface
    {
        if (self::$logger === null) {
            self::$logger = new Logger(
                (string) config('app.log_path', dirname(__DIR__) . '/storage/logs'),
                (string) config('app.log_level', 'info'),
            );
        }

        return self::$logger;
    }

    /**
     * @param array<int,class-string<Middleware>|Middleware> $globalMiddleware
     */
    public static function handle(Router $router, array $globalMiddleware = []): never
    {
        $requestId = bin2hex(random_bytes(8));
        ApiResponse::header('X-Request-Id', $requestId);

        try {
            $request = Request::capture();
            $request->setAttribute('request_id', $requestId);

            $result = (self::pipeline($router, $globalMiddleware))($request);

            // A handler that returned instead of calling ApiResponse still gets
            // wrapped, so there is exactly one envelope shape in the wild.
            ApiResponse::success($result);
        } catch (ApiError $e) {
            if ($e->status() >= 500) {
                self::logger()->error($e->getMessage(), ['request_id' => $requestId, 'exception' => $e]);
            }

            ApiResponse::fromApiError($e);
        } catch (Throwable $e) {
            self::logger()->error('Unhandled exception', [
                'request_id' => $requestId,
                'exception'  => $e,
                'trace'      => $e->getTraceAsString(),
            ]);

            $debug = (bool) config('app.debug', false);

            ApiResponse::error(
                ErrorCode::INTERNAL_ERROR,
                // The real message is only ever shown when APP_DEBUG is on, and
                // APP_DEBUG is false in production (doc 15).
                $debug ? $e->getMessage() : 'Something went wrong',
                $debug ? [['field' => '', 'message' => $e->getFile() . ':' . $e->getLine()]] : [],
            );
        }
    }
}

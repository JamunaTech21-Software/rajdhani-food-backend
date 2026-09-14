<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Http\Request;

/**
 * Middleware runs outside-in and unwinds after the handler, so a middleware can
 * act both before and after the controller. AuditLog depends on that.
 */
interface Middleware
{
    /**
     * @param callable(Request):mixed $next
     */
    public function handle(Request $request, callable $next): mixed;
}

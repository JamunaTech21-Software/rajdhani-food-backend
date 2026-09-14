<?php

declare(strict_types=1);

namespace Rajdhani\Middleware;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Http\Request;

/**
 * Rejects array-valued query parameters — the HTTP parameter-pollution guard
 * from §14.2.
 *
 * PHP turns `?status=A&status[]=B` and `?status[]=A&status[]=B` into an *array*
 * in `$_GET`, where every other language in the stack would give a string. Code
 * written for the string case then does something surprising: `(string) $array`
 * emits "Array", `in_array()` against a list behaves differently, and a
 * validator checking `strlen()` throws. None of that is visible in review,
 * because the reviewer reads `$request->query('status')` and thinks "string".
 *
 * The classic exploit is a filter that is safe for one value and not for a
 * list — or two front-ends disagreeing about which duplicate wins, so a WAF
 * inspects the first and the application uses the last.
 *
 * **No current endpoint takes an array in the query string.** Section 9 uses
 * repeated scalars and comma-separated lists, so rejecting arrays outright is
 * correct today and will need relaxing per-route the day a route genuinely
 * wants one — at which point that route is making a deliberate decision instead
 * of inheriting an accident.
 *
 * Only the query string is guarded. A JSON body is parsed by `json_decode` and
 * arrays there are structure the client meant.
 */
final class GuardQueryParameters implements Middleware
{
    public function handle(Request $request, callable $next): mixed
    {
        foreach ($request->query as $name => $value) {
            if (is_array($value)) {
                throw ApiError::validation('Query parameters must not be repeated', [
                    ['field' => (string) $name, 'message' => 'Send this parameter once, as a single value'],
                ]);
            }
        }

        return $next($request);
    }
}

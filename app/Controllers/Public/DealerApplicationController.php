<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\DealerApplicationService;

/**
 * `POST /public/dealer-applications` (doc §9.6, §10.3, RTPP-30).
 * Rate-limited (`ThrottleForm('dealer_application')`) — wired in
 * `routes/public.php`. No customer identity to attribute: unlike enquiries,
 * `dealer_applications` has no `customer_id` column (doc §8.8), so there is
 * no `OptionalCustomer` on this route.
 */
final class DealerApplicationController
{
    public function __construct(
        private readonly DealerApplicationService $applications = new DealerApplicationService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        return $this->applications->submit($request->ip, $request->body);
    }
}

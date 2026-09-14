<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\EnquiryService;

/**
 * `POST /public/enquiries` (doc §9.6, RTPP-29). Rate-limited
 * (`ThrottleForm('enquiry')`) and identity-optional (`OptionalCustomer`) —
 * wired in `routes/public.php`.
 */
final class EnquiryController
{
    public function __construct(
        private readonly EnquiryService $enquiries = new EnquiryService(),
    ) {
    }

    /** @return array<string,mixed> */
    public function store(Request $request): array
    {
        $customerId = $request->attribute('customer_id');

        return $this->enquiries->submit(is_string($customerId) ? $customerId : null, $request->ip, $request->body);
    }
}

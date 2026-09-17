<?php

declare(strict_types=1);

namespace Rajdhani\Controllers\Public;

use Rajdhani\Http\Request;
use Rajdhani\Services\CertificationService;

/**
 * `GET /public/certifications` (doc §9.3, §10.4; RTPP-67) — active
 * certifications, the same row rendered on both About and Quality. No
 * parameters, no authentication.
 */
final class CertificationController
{
    public function __construct(
        private readonly CertificationService $certifications = new CertificationService(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(Request $request): array
    {
        return $this->certifications->publicList();
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Support;

/**
 * Where a reCAPTCHA v3 token's verdict comes from (doc §14.2; RTPP-34).
 *
 * An interface for the same reason `JwkSource` is one: `RecaptchaVerifier`
 * can then be tested against every verdict shape (a real pass, a low
 * score, a network failure) without calling Google from the test suite —
 * slow, offline-hostile, and untestable for the failure cases that matter
 * most (what happens when Google is unreachable).
 */
interface RecaptchaClient
{
    /**
     * @return array{success:bool,score:float,action:?string}
     */
    public function verify(string $token, string $remoteIp): array;
}

<?php

declare(strict_types=1);

namespace Rajdhani\Tests\Support;

use Rajdhani\Support\RecaptchaClient;
use RuntimeException;

/**
 * A scriptable stand-in for Google's `siteverify` (RTPP-34) — every verdict
 * shape `RecaptchaVerifierTest` needs, including the transport failure a
 * real Google outage would produce, none of which a real HTTP call could
 * produce on demand or offline.
 */
final class FakeRecaptchaClient implements RecaptchaClient
{
    /** @var array{success:bool,score:float,action:?string}|null */
    public ?array $nextResult = null;

    public bool $throwOnVerify = false;

    /** What the verifier actually sent — the tests read this to prove the token and remote IP were passed through. */
    public ?string $lastToken = null;
    public ?string $lastRemoteIp = null;

    /**
     * @return array{success:bool,score:float,action:?string}
     */
    public function verify(string $token, string $remoteIp): array
    {
        $this->lastToken = $token;
        $this->lastRemoteIp = $remoteIp;

        if ($this->throwOnVerify) {
            throw new RuntimeException('Simulated: Google unreachable.');
        }

        return $this->nextResult ?? ['success' => true, 'score' => 0.9, 'action' => null];
    }
}

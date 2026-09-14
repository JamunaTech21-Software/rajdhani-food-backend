<?php

declare(strict_types=1);

namespace Rajdhani\Support;

/**
 * Google's `siteverify` endpoint (doc §14.2; RTPP-34).
 *
 * A transport failure (Google unreachable, a timeout) is a `RuntimeException`
 * from `HttpClient`, deliberately left to propagate rather than caught here —
 * `RecaptchaVerifier` is where the "fail open or fail closed" decision
 * belongs, not this thin transport wrapper.
 */
final class GoogleRecaptchaClient implements RecaptchaClient
{
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    /**
     * @return array{success:bool,score:float,action:?string}
     */
    public function verify(string $token, string $remoteIp): array
    {
        $response = $this->http->post((string) config('recaptcha.verify_url'), [
            'secret'   => (string) config('recaptcha.secret_key'),
            'response' => $token,
            'remoteip' => $remoteIp,
        ]);

        /** @var mixed $decoded */
        $decoded = json_decode($response['body'], true, 8);

        if (!is_array($decoded)) {
            return ['success' => false, 'score' => 0.0, 'action' => null];
        }

        return [
            'success' => ($decoded['success'] ?? false) === true,
            'score'   => is_numeric($decoded['score'] ?? null) ? (float) $decoded['score'] : 0.0,
            'action'  => is_string($decoded['action'] ?? null) ? $decoded['action'] : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Kernel;
use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Support\GoogleRecaptchaClient;
use Rajdhani\Support\RecaptchaClient;
use Throwable;

/**
 * reCAPTCHA v3 verification (doc §14.2; RTPP-34), used by `VerifyHuman` on
 * every one of the ticket's five public forms.
 *
 * **An unconfigured secret key is a logged skip, not an error** — the
 * identical resilience choice `Mailer` already makes for an unconfigured
 * `MAIL_HOST` (RTPP-33): reCAPTCHA keys are a client-supplied credential
 * (doc §19) not yet in this repo's `.env`, and every environment without
 * one (including this test suite) has to keep working. Honeypot and the
 * per-form rate limit (`ThrottleForm`) are still active regardless — this
 * is one of three layers, not the only one.
 *
 * **A transport failure fails open, logged as a warning.** Google being
 * briefly unreachable is not a reason to block every legitimate submission
 * on this site; a bot getting through during a Google outage is a smaller
 * cost than a real customer being unable to submit an enquiry, and the
 * other two layers of defence don't depend on Google's uptime.
 *
 * **A configured key with a genuinely bad verdict — no token, a failed
 * check, a low score, or a token minted for a different action — fails
 * closed.** That is the one case this class exists to catch, and it is
 * deliberately indistinguishable from every other rejection reason in the
 * response: the ticket's own DoD says rejections must not reveal the
 * threshold.
 */
final class RecaptchaVerifier
{
    private const DEFAULT_THRESHOLD = 0.5;

    private readonly ?string $secretKey;
    private readonly ?float $thresholdOverride;

    /**
     * `$secretKey` defaults from `config('recaptcha.secret_key')`, resolved
     * here rather than read fresh inside `verify()`, for the same reason
     * `EnquiryService` etc. resolve their `?PDO $connection` in the
     * constructor: `config()` caches each file's values in a `static` array
     * for the life of the PHP process (`app/Support/functions.php`), so a
     * test that needs "configured" behaviour passes an explicit value here
     * instead of mutating global config state that would leak into every
     * other test in the same run.
     *
     * `$threshold` exists for the identical reason on the other side of
     * `verify()`: a plain `TestCase` (no database) can prove every score
     * comparison without ever constructing a `SettingsRepository` that
     * would otherwise be the only thing in the test forcing a live
     * connection.
     */
    public function __construct(
        private readonly RecaptchaClient $client = new GoogleRecaptchaClient(),
        private readonly ?SettingsRepository $settings = null,
        ?string $secretKey = null,
        ?float $threshold = null,
    ) {
        $configured = config('recaptcha.secret_key');
        $this->secretKey = $secretKey ?? (is_string($configured) && $configured !== '' ? $configured : null);
        $this->thresholdOverride = $threshold;
    }

    /**
     * @throws ApiError FORBIDDEN when a configured check genuinely fails
     */
    public function verify(string $token, string $action, string $remoteIp): void
    {
        if ($this->secretKey === null) {
            Kernel::logger()->info('reCAPTCHA skipped — not configured', ['action' => $action]);

            return;
        }

        if ($token === '') {
            throw $this->rejected($action, 'no token');
        }

        try {
            $result = $this->client->verify($token, $remoteIp);
        } catch (Throwable $e) {
            Kernel::logger()->warning('reCAPTCHA verification failed — allowing the request through', [
                'action' => $action, 'error' => $e->getMessage(),
            ]);

            return;
        }

        $threshold = $this->threshold();

        if (!$result['success'] || $result['score'] < $threshold) {
            throw $this->rejected($action, "success={$this->bool($result['success'])} score={$result['score']} threshold={$threshold}");
        }

        if ($result['action'] !== null && $result['action'] !== $action) {
            throw $this->rejected($action, "action mismatch: expected {$action}, got {$result['action']}");
        }
    }

    private function threshold(): float
    {
        if ($this->thresholdOverride !== null) {
            return $this->thresholdOverride;
        }

        $configured = ($this->settings ?? new SettingsRepository())->get('recaptcha_score_threshold');

        return $configured !== null && is_numeric($configured) ? (float) $configured : self::DEFAULT_THRESHOLD;
    }

    private function rejected(string $action, string $reason): ApiError
    {
        Kernel::logger()->info('reCAPTCHA rejected a submission', ['action' => $action, 'reason' => $reason]);

        // Deliberately generic — the DoD requires a clear error without
        // revealing the threshold or which of the two checks (honeypot,
        // reCAPTCHA) actually failed.
        return ApiError::forbidden('We could not verify this submission. Please try again.');
    }

    private function bool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}

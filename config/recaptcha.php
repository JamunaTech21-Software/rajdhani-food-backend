<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

/**
 * Section 14.2. reCAPTCHA v3 keys are a client-supplied credential
 * (§19: "reCAPTCHA keys are provided by the client") — like the Gmail App
 * Password (RTPP-33), not yet in this repo's `.env`. `RecaptchaVerifier`
 * treats an unconfigured secret key as "skip, logged" rather than an
 * error, the same resilience choice `Mailer` already makes for SMTP.
 */
return [
    'secret_key'  => Env::get('RECAPTCHA_SECRET_KEY'),
    'verify_url'  => 'https://www.google.com/recaptcha/api/siteverify',
    'timeout_seconds' => 5,
];

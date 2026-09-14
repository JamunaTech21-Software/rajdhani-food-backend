<?php

declare(strict_types=1);

use Rajdhani\Support\Env;

/**
 * Section 14.4. There is no queue worker on shared hosting, so mail is sent
 * inline after the submission has already been committed, inside a try/catch
 * with a short timeout. A dead SMTP server must never fail a form submission.
 */
return [
    'host'       => Env::get('MAIL_HOST'),
    'port'       => Env::int('MAIL_PORT', 587),
    'username'   => Env::get('MAIL_USERNAME'),
    'password'   => Env::get('MAIL_PASSWORD'),
    'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),

    'from' => [
        'address' => Env::get('MAIL_FROM_ADDRESS'),
        'name'    => Env::get('MAIL_FROM_NAME', 'Rajdhani Food Products'),
    ],

    // Kept short on purpose: this timeout is how long a visitor waits for a form
    // response when the mail server is unreachable.
    'timeout_seconds' => 5,
];

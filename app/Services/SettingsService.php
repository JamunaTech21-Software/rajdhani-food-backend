<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Services\Concerns\ValidatesInput;

/**
 * `GET`/`PUT /admin/settings` (doc §8.2, §9.8; RTPP-32) — Super Admin only,
 * the one role §7.3 grants `SETTINGS` to at all.
 *
 * The recipient lists here (`enquiry_notify_emails`, `dealer_notify_emails`,
 * `contact_notify_emails`, `newsletter_notify_emails`) are what makes the
 * ticket's "notification recipient lists actually drive who receives each
 * email type" true from `RTPP-33` onward: this ticket makes them
 * admin-editable and gives them somewhere real to be read from;
 * `RTPP-33`'s mailer is what actually reads them, still `To Do`.
 */
final class SettingsService
{
    use ValidatesInput;

    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository(),
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function index(): array
    {
        return array_map($this->view(...), $this->settings->listAll());
    }

    /**
     * `settings.key` is an allowlist populated only by `SettingsSeeder` (or a
     * future migration) — this endpoint may change an existing key's value,
     * never create a new one. A typo in the request body is therefore a
     * `422` naming the unknown key, not a silently-created dead setting.
     *
     * @param array<string,mixed> $input
     *
     * @return list<array<string,mixed>>
     */
    public function update(array $input): array
    {
        $values = $this->normalisedValues($input);
        $missing = $this->settings->updateMany($values);

        if ($missing !== []) {
            throw ApiError::validation('Unknown setting key(s)', array_map(
                static fn (string $key): array => ['field' => 'settings', 'message' => "No such setting: {$key}"],
                $missing,
            ));
        }

        return $this->index();
    }

    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,string>
     */
    private function normalisedValues(array $input): array
    {
        $entries = $input['settings'] ?? null;

        if (!is_array($entries) || $entries === []) {
            throw $this->invalid('settings', 'This field is required and must be a non-empty list');
        }

        $values = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['key'] ?? null) || $entry['key'] === '') {
                throw $this->invalid('settings', 'Every entry needs a string "key"');
            }

            $value = $entry['value'] ?? null;

            if ($value !== null && !is_scalar($value)) {
                throw $this->invalid('settings', "The value for '{$entry['key']}' must be a string");
            }

            $values[$entry['key']] = trim((string) ($value ?? ''));
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function view(array $row): array
    {
        return [
            'key'   => (string) $row['key'],
            'value' => (string) $row['value'],
            'group' => $row['group'] === null ? null : (string) $row['group'],
        ];
    }
}

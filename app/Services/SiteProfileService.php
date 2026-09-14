<?php

declare(strict_types=1);

namespace Rajdhani\Services;

use Rajdhani\Helpers\ApiError;
use Rajdhani\Repositories\NavigationRepository;
use Rajdhani\Repositories\SettingsRepository;
use Rajdhani\Repositories\SiteProfileRepository;

/**
 * The site's identity, and the composed payload the front-end boots from
 * (doc §6, §9.3).
 *
 * This is what replaced brand resolution. Under v2.0 the front-end told the API
 * which brand it was via an `X-Brand` header and the API looked up a row; now
 * there is one row and no header, so **no request carries a site identifier**
 * (doc §6, rule 5). `GET /public/layout` needs no authentication, no header and
 * no query string.
 *
 * Everything here comes out of the database rather than a config file, because
 * §18.2 requires the client to change colours, contact details and menus from
 * the admin panel without a deployment. A hard-coded hex value anywhere in the
 * stack breaks that, which is why the theme travels in this payload and the
 * front-end applies it as CSS custom properties (doc §5.2).
 */
final class SiteProfileService
{
    /**
     * Cached for the life of the request.
     *
     * PHP-FPM gives each request a fresh process, so this is a per-request
     * memo and nothing more — there is no cross-request cache to invalidate,
     * and an edit in the admin panel is visible on the very next request. That
     * is exactly what the §18.2 acceptance criterion tests.
     *
     * @var array<string,mixed>|null
     */
    private ?array $profile = null;

    public function __construct(
        private readonly SiteProfileRepository $profiles = new SiteProfileRepository(),
        private readonly NavigationRepository $navigation = new NavigationRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
    ) {
    }

    /**
     * The raw profile row, memoised.
     *
     * @return array<string,mixed>
     */
    public function profile(): array
    {
        if ($this->profile !== null) {
            return $this->profile;
        }

        $profile = $this->profiles->find();

        if ($profile === null) {
            // Not a 404: the caller did nothing wrong. An unseeded database is
            // a deployment that is not finished, and saying so plainly beats a
            // front-end rendering an empty header.
            throw ApiError::internal('The site profile has not been set up yet. Run the seeders.');
        }

        return $this->profile = $profile;
    }

    /**
     * Everything `GET /public/layout` returns, in one call.
     *
     * One endpoint rather than four because the front-end cannot paint a header
     * until it has all of it, and four round trips on a cold load is three too
     * many on a phone in Bangladesh.
     *
     * @return array<string,mixed>
     */
    public function layout(): array
    {
        $profile = $this->profile();

        return [
            'site'      => $this->publicProfile($profile),
            'menus'     => $this->groupedMenus(),
            'social'    => $this->socialLinks(),
            'newsletter' => [
                // §9.3 lists "newsletter config" in this payload. All the
                // front-end needs is whether to render the footer form; the
                // subscribe endpoint itself is a later issue.
                'enabled' => $this->settings->bool('newsletter_enabled', true),
            ],
        ];
    }

    /**
     * Apply an editor's changes to the singleton.
     *
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed> the profile as it now stands
     */
    public function update(array $input): array
    {
        $fields = [];

        foreach (self::EDITABLE as $column => $rule) {
            if (!array_key_exists($column, $input)) {
                continue;
            }

            $fields[$column] = $this->coerce($column, $rule, $input[$column]);
        }

        if ($fields === []) {
            throw ApiError::validation('Nothing to update', [
                ['field' => '', 'message' => 'Send at least one editable field'],
            ]);
        }

        $this->profiles->update($fields);

        // Drop the memo: the row just changed underneath it.
        $this->profile = null;

        return $this->publicProfile($this->profile());
    }

    /**
     * What an editor may change, and how each value is validated.
     *
     * An allowlist, not a filter on the request body. `id`, `created_at` and
     * `updated_at` are all present on the row and none of them belong to an
     * editor — `id` least of all, since changing it would violate the singleton
     * constraint.
     *
     * @var array<string,string>
     */
    private const EDITABLE = [
        'name'             => 'text:255:required',
        'tagline'          => 'text:255',
        'logo_light_id'    => 'ulid',
        'logo_dark_id'     => 'ulid',
        'favicon_id'       => 'ulid',
        'og_image_id'      => 'ulid',
        'primary_color'    => 'color',
        'secondary_color'  => 'color',
        'accent_color'     => 'color',
        'address_line'     => 'text:255',
        'city'             => 'text:128',
        'country'          => 'text:128',
        'phone_primary'    => 'text:32',
        'phone_secondary'  => 'text:32',
        'email_primary'    => 'email',
        'email_secondary'  => 'email',
        'website_url'      => 'text:255',
        'business_hours'   => 'text:255',
        'map_latitude'     => 'latitude',
        'map_longitude'    => 'longitude',
        'map_embed_url'    => 'text:2048',
        'footer_about'     => 'text:2048',
        'copyright_text'   => 'text:255',
        'meta_title'       => 'text:255',
        'meta_description' => 'text:2048',
    ];

    private function coerce(string $column, string $rule, mixed $value): string|float|null
    {
        [$type, $limit, $required] = array_pad(explode(':', $rule), 3, null);

        if ($value !== null && !is_scalar($value)) {
            throw $this->invalid($column, 'Expected a single value');
        }

        $raw = $value === null ? null : trim((string) $value);

        if ($raw === '') {
            $raw = null;
        }

        if ($raw === null) {
            if ($required === 'required') {
                throw $this->invalid($column, 'This field is required');
            }

            return null;
        }

        return match ($type) {
            'color' => $this->color($column, $raw),
            'email' => $this->email($column, $raw),

            // Stored as DOUBLE. Rejecting out-of-range values here means a
            // typo shows up as a validation error rather than as a map
            // centred in the ocean.
            'latitude'  => $this->coordinate($column, $raw, 90.0),
            'longitude' => $this->coordinate($column, $raw, 180.0),
            default     => $this->text($column, $raw, (int) $limit),
        };
    }

    private function color(string $column, string $value): string
    {
        // VARCHAR(9): #RGB, #RRGGBB or #RRGGBBAA. Anything else would reach the
        // front-end as a CSS custom property and fail silently there — an
        // unreadable site rather than an error.
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) !== 1) {
            throw $this->invalid($column, 'Use a hex colour such as #1B5E20');
        }

        return strtoupper($value);
    }

    private function email(string $column, string $value): string
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw $this->invalid($column, 'Enter a valid email address');
        }

        return $value;
    }

    private function coordinate(string $column, string $value, float $bound): float
    {
        if (!is_numeric($value) || abs((float) $value) > $bound) {
            throw $this->invalid($column, sprintf('Enter a number between -%g and %g', $bound, $bound));
        }

        return (float) $value;
    }

    private function text(string $column, string $value, int $limit): string
    {
        if (mb_strlen($value, 'UTF-8') > $limit) {
            throw $this->invalid($column, "Must be {$limit} characters or fewer");
        }

        return $value;
    }

    private function invalid(string $field, string $message): ApiError
    {
        return ApiError::validation('Some fields need attention', [['field' => $field, 'message' => $message]]);
    }

    /**
     * Menu links grouped by their location, so the front-end does not have to.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    private function groupedMenus(): array
    {
        // Every known location is present even when empty. A front-end reading
        // $menus['footer_quick'] should get [] rather than an undefined index
        // because an editor happened to deactivate every link in it.
        $grouped = ['header' => [], 'footer_quick' => [], 'footer_products' => [], 'legal' => []];

        foreach ($this->navigation->activeMenuLinks() as $link) {
            $location = (string) $link['location'];
            $grouped[$location] ??= [];

            $grouped[$location][] = [
                'id'              => (string) $link['id'],
                'label'           => (string) $link['label'],
                'url'             => (string) $link['url'],
                'parent_id'       => $link['parent_id'] === null ? null : (string) $link['parent_id'],
                'open_in_new_tab' => (int) $link['open_in_new_tab'] === 1,
            ];
        }

        return $grouped;
    }

    /** @return list<array<string,mixed>> */
    private function socialLinks(): array
    {
        return array_map(
            static fn (array $row): array => [
                'platform'  => (string) $row['platform'],
                'url'       => (string) $row['url'],
                'icon_name' => $row['icon_name'] === null ? null : (string) $row['icon_name'],
            ],
            $this->navigation->activeSocialLinks(),
        );
    }

    /**
     * The profile as the API exposes it.
     *
     * Images are returned as `{id, url}` pairs, not bare ids: the public site
     * needs a URL it can render, and the admin panel needs the id it must send
     * back to change it. Returning only one of the two would force a second
     * request from whichever consumer got the wrong half.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function publicProfile(array $row): array
    {
        return [
            'name'    => (string) $row['name'],
            'tagline' => $this->nullableString($row['tagline']),

            'logos' => [
                'light'   => $this->image($row['logo_light_id'], $row['logo_light_url'], $row['logo_light_alt'] ?? null),
                'dark'    => $this->image($row['logo_dark_id'], $row['logo_dark_url'], $row['logo_dark_alt'] ?? null),
                'favicon' => $this->image($row['favicon_id'], $row['favicon_url'], null),
                'og'      => $this->image($row['og_image_id'], $row['og_image_url'], null),
            ],

            // The whole point of §18.2: these are data, never constants.
            'theme' => [
                'primary'   => (string) $row['primary_color'],
                'secondary' => (string) $row['secondary_color'],
                'accent'    => (string) $row['accent_color'],
            ],

            'contact' => [
                'address_line'    => $this->nullableString($row['address_line']),
                'city'            => $this->nullableString($row['city']),
                'country'         => $this->nullableString($row['country']),
                'phone_primary'   => $this->nullableString($row['phone_primary']),
                'phone_secondary' => $this->nullableString($row['phone_secondary']),
                'email_primary'   => $this->nullableString($row['email_primary']),
                'email_secondary' => $this->nullableString($row['email_secondary']),
                'website_url'     => $this->nullableString($row['website_url']),
                'business_hours'  => $this->nullableString($row['business_hours']),
            ],

            'map' => [
                // Null rather than 0.0 when unset — (0, 0) is a real place in
                // the Gulf of Guinea, and a map centred there is worse than a
                // map the front-end knows not to render.
                'latitude'  => $row['map_latitude'] === null ? null : (float) $row['map_latitude'],
                'longitude' => $row['map_longitude'] === null ? null : (float) $row['map_longitude'],
                'embed_url' => $this->nullableString($row['map_embed_url']),
            ],

            'footer' => [
                'about'     => $this->nullableString($row['footer_about']),
                'copyright' => $this->nullableString($row['copyright_text']),
            ],

            'seo' => [
                'meta_title'       => $this->nullableString($row['meta_title']),
                'meta_description' => $this->nullableString($row['meta_description']),
            ],
        ];
    }

    /** @return array{id:string,url:string,alt:string|null}|null */
    private function image(mixed $id, mixed $url, mixed $alt): ?array
    {
        if (!is_string($id) || $id === '' || !is_string($url) || $url === '') {
            return null;
        }

        return ['id' => $id, 'url' => $url, 'alt' => $this->nullableString($alt)];
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}

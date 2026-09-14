<?php

declare(strict_types=1);

namespace Rajdhani\Auth;

/**
 * The §7.3 permission matrix, as data.
 *
 * This class is the single source of truth for admin authorisation, and role is
 * the *only* dimension of it (doc §7.4) — there is no second check, no brand
 * access, no per-record ownership except the one OWN cell below. Under v2.0
 * access was the intersection of role and `admin_brand_access`; with one site
 * the intersection is just the role.
 *
 * Written as a table rather than as a series of `if ($role === …)` branches for
 * three reasons: it can be diffed against the document row by row, it can be
 * enumerated by a test so no cell goes uncovered, and it can be handed to the
 * dashboard so the navigation it hides matches what the API actually allows.
 *
 * **A capability missing from a role's row is NONE.** Deny is the default, so
 * adding a capability without deciding on its permissions locks everyone out
 * rather than letting everyone in.
 */
final class RolePolicy
{
    /**
     * The matrix. Rows are §7.3's rows; read it against the document.
     *
     * @var array<string,array<string,AccessLevel>>
     */
    private const MATRIX = [
        Role::SUPER_ADMIN->value => [
            Capability::DASHBOARD->value           => AccessLevel::READ,
            Capability::PRODUCTS->value            => AccessLevel::WRITE,
            Capability::CONTENT->value             => AccessLevel::WRITE,
            Capability::GALLERY->value             => AccessLevel::WRITE,
            Capability::NEWS->value                => AccessLevel::WRITE,
            Capability::MARKETING->value           => AccessLevel::WRITE,
            Capability::REVIEWS->value             => AccessLevel::WRITE,
            Capability::DOWNLOADS->value           => AccessLevel::WRITE,
            Capability::ENQUIRIES->value           => AccessLevel::WRITE,
            Capability::DEALER_APPLICATIONS->value => AccessLevel::WRITE,
            Capability::CONTACT_MESSAGES->value    => AccessLevel::WRITE,
            Capability::NEWSLETTER->value          => AccessLevel::WRITE,
            Capability::SETTINGS->value            => AccessLevel::WRITE,
            Capability::ADMIN_USERS->value         => AccessLevel::WRITE,

            // READ, not WRITE: the audit log is written by the system and by
            // nobody else. A role that could edit it would make it worthless as
            // evidence.
            Capability::AUDIT_LOG->value => AccessLevel::READ,
            Capability::MEDIA->value     => AccessLevel::WRITE,
        ],

        Role::EDITOR->value => [
            Capability::DASHBOARD->value => AccessLevel::READ,
            Capability::PRODUCTS->value  => AccessLevel::WRITE,
            Capability::CONTENT->value   => AccessLevel::WRITE,
            Capability::GALLERY->value   => AccessLevel::WRITE,
            Capability::NEWS->value      => AccessLevel::WRITE,
            Capability::MARKETING->value => AccessLevel::WRITE,
            Capability::REVIEWS->value   => AccessLevel::WRITE,
            Capability::DOWNLOADS->value => AccessLevel::WRITE,

            // Editors see the leads but do not work them — the distinction the
            // issue calls out. Read is not "nearly write".
            Capability::ENQUIRIES->value           => AccessLevel::READ,
            Capability::DEALER_APPLICATIONS->value => AccessLevel::READ,
            Capability::CONTACT_MESSAGES->value    => AccessLevel::READ,

            // The one OWN cell in the whole matrix: an Editor may delete media
            // they uploaded, not media somebody else did.
            Capability::MEDIA->value => AccessLevel::OWN,
        ],

        Role::SALES->value => [
            // "leads only" in §7.3. Access is the same READ; which widgets the
            // overview returns is a filtering decision inside the dashboard
            // service, not an authorisation one.
            Capability::DASHBOARD->value => AccessLevel::READ,

            // Sales quote from the catalogue but do not edit it.
            Capability::PRODUCTS->value  => AccessLevel::READ,
            Capability::DOWNLOADS->value => AccessLevel::READ,

            Capability::ENQUIRIES->value           => AccessLevel::WRITE,
            Capability::DEALER_APPLICATIONS->value => AccessLevel::WRITE,
            Capability::CONTACT_MESSAGES->value    => AccessLevel::WRITE,
            Capability::NEWSLETTER->value          => AccessLevel::WRITE,
        ],
    ];

    /** What this role holds for this capability. Anything unlisted is NONE. */
    public static function levelFor(Role $role, Capability $capability): AccessLevel
    {
        return self::MATRIX[$role->value][$capability->value] ?? AccessLevel::NONE;
    }

    public static function allows(Role $role, Capability $capability, AccessLevel $required): bool
    {
        return self::levelFor($role, $capability)->satisfies($required);
    }

    /**
     * Which rows of a capability this role may act on.
     *
     * `'all'` for a full holder, `'own'` for the Editor's media cell, `'none'`
     * for no access. A service handling a route gated at OWN **must** consult
     * this before touching a row it did not create — RequireRole records the
     * answer on the request as `row_scope` for exactly that purpose.
     *
     * @return 'all'|'own'|'none'
     */
    public static function scopeFor(Role $role, Capability $capability): string
    {
        return match (self::levelFor($role, $capability)) {
            AccessLevel::WRITE => 'all',
            AccessLevel::OWN   => 'own',
            default            => 'none',
        };
    }

    /**
     * The whole row for one role, as the dashboard needs it.
     *
     * §7.3: "The dashboard hides unavailable navigation, but the API is the
     * source of truth." Serving the matrix from the same constant the middleware
     * enforces is what keeps those two statements from diverging — a hidden
     * button and a 403 should never disagree.
     *
     * @return array<string,string> capability value => level label
     */
    public static function permissionsFor(Role $role): array
    {
        $permissions = [];

        foreach (Capability::cases() as $capability) {
            $permissions[$capability->value] = self::levelFor($role, $capability)->label();
        }

        return $permissions;
    }
}

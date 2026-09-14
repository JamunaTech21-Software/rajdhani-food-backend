# Rajdhani Tea Platform — Backend API

PHP 8.3 REST API over MySQL 8, deployed to shared cPanel hosting.

**Requirements:** `../../Rajdhani_Project_Doc.md` v3.0 — the source of truth.
**Plan:** `../../plan.md` v3.1 (backend only).

This is the API only. The customer site and admin dashboard are separate React +
Vite applications built by another developer; §9 of the requirements document is
the contract between us.

---

## Local setup

Two things differ from a stock machine and both are deliberate.

**PHP 8.3 is not on `PATH`.** It is installed keg-only via Homebrew so it cannot
shadow a system PHP:

```bash
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"
```

**MySQL 8 runs on port 3307, not 3306.** This machine already runs MySQL 9.6 on
the default port. The production target is MySQL 8 (or MariaDB 10.6+), and
authoring a schema against 9.x risks 9-only syntax reaching a host that cannot
run it. So 8.0 gets its own datadir and port, and 9.6 is left alone:

```bash
./bin/mysql8.sh start     # start on :3307
./bin/mysql8.sh status
./bin/mysql8.sh cli       # mysql shell
./bin/mysql8.sh stop
```

Then:

```bash
composer install
cp .env.example .env      # fill in DB_DATABASE and the JWT secrets
./bin/mysql8.sh cli -e "CREATE DATABASE rajdhani_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php bin/migrate.php up
php bin/seed.php          # prints the Super Admin invite token — keep it
php -S 127.0.0.1:8000 -t public public/index.php
```

```bash
curl http://127.0.0.1:8000/api/v1/health
curl http://127.0.0.1:8000/api/v1/health/db
```

---

## Commands

| Command | Does |
|---|---|
| `composer check` | style, static analysis and tests — what CI runs |
| `composer test` | PHPUnit |
| `composer stan` | PHPStan level 8 |
| `composer lint` | php-cs-fixer, dry run |
| `composer fix` | php-cs-fixer, applied |
| `php bin/migrate.php status` | applied and pending migrations |
| `php bin/migrate.php up` | apply everything pending |
| `php bin/migrate.php verify` | confirm applied files match their checksums |
| `php bin/seed.php` | fill an empty database; safe to re-run |
| `php bin/seed.php --list` | the seeders and the order they run in |
| `php bin/seed.php --only=NewsSeeder` | run one seeder |
| `php bin/openapi.php check` | confirm the spec matches the routes |
| `php bin/openapi.php routes` | print the routing table |
| `./vendor/bin/phpunit --testsuite Unit` | unit tests, no database needed |
| `./vendor/bin/phpunit --testsuite Feature` | database-backed tests (skipped if no database) |

---

## Migrations

Forward-only (doc §16.4). The runner records a sha256 of every file as applied
and **refuses to run if an applied file later differs** — an applied migration is
immutable, and a change to one means production and this repository have silently
diverged. To change the schema, add a new migration.

The seven schema files are **generated from §8 of the requirements document**, in
foreign-key dependency order computed from the DDL itself. Two consequences worth
knowing:

- §8's presentation order is *not* apply order. `site_profile` is documented
  first but must be created 32nd, because it references `media_assets`.
- If §8 changes, regenerate rather than hand-editing, so the schema and the
  document cannot drift apart.

---

## Seeding

`php bin/seed.php` brings an empty database to a usable state: the site profile
the header reads, the 64 districts the dealer form offers, a Super Admin to log
in as, and enough demo content that every public endpoint returns something.

**Running it twice changes nothing.** The report prints inserted / updated /
unchanged per table, so "nothing happened" is visible rather than assumed, and
CI fails the build if a second run is not a no-op. The whole run is one
transaction — a seeder that fails half way writes nothing at all.

Which write strategy a seeder uses is a decision about who owns the data:

| Strategy | Used for | On a second run |
|---|---|---|
| `upsert()` | reference data this project owns — districts, upazilas | **corrects** the row; a fix to the JSON reaches an existing database |
| `insertIfAbsent()` | anything the client owns once it exists — site profile, products, copy | **leaves it alone**; never overwrites what an editor typed |

That distinction is the reason seeding is safe to run against staging. Getting
it the wrong way round would put placeholder copy back over the client's content
every deploy.

Three things worth knowing:

- **The Super Admin is seeded without a password.** `admin_users.password_hash`
  is nullable precisely so the account can exist unclaimed: it holds an invite
  token, and login must reject it until the invite is accepted (doc §7.2).
  Seeding a default password instead would leave a working `admin` / `admin123`
  on a public host. The token is printed by the runner because SMTP is not
  configured yet (RTPP-17).
- **The media rows are placeholders, not uploads.** Cloudinary has no
  credentials yet, so `media_assets` gets rows under a `rajdhani/demo/` prefix
  pointing at a placeholder image service. They are replaced under RTPP-86, and
  the prefix makes them trivial to find and delete.
- **The copy is placeholder** (doc §19), including the upazila spellings, which
  drive a public dropdown and have not been confirmed by the client (RTPP-17).

Source data lives in `database/seed-data/` — inside `backend/api` rather than at
the project root, so the seeders still work from the deployed directory.

---

## Authentication

Two separate systems share this backend (doc §7). They differ in how a caller
proves who they are; everything after that — rotation, revocation, cookies — is
shared. **An admin token is never valid on a customer route**, enforced by the
`aud` claim inside `JwtHelper::decode()` rather than by each middleware, so no
future route can forget the check.

| | Access token | Refresh token |
|---|---|---|
| What it is | HS256 JWT, `sub` + `aud` + `role` | 32 random bytes; the database row is the authority |
| Lifetime | 20 minutes (admin) | 7 days (admin) |
| Carried in | `Authorization: Bearer`, held in memory | `HttpOnly` cookie, path-scoped to `/api/v1/auth` |
| Revocable | no — it expires | yes, and that is why it is not a JWT |

**Rotation and breach detection.** Every refresh spends the presented token and
issues a new one carrying the same `family_id`. A token that has already been
spent arriving again means two parties hold it, and there is no way to tell the
thief from the victim — so the whole family is revoked and both are forced back
to a password login, which the attacker cannot complete.

**The seeded Super Admin has no password.** It holds an invite token
(`bin/seed.php` prints it) and cannot be logged into until
`POST /auth/admin/invite/:token/accept` sets one. Login rejects a null
`password_hash` explicitly, not merely as a side effect of the hash check.

**Login throttle** (doc §7.2, §14.2): five failures per email per fifteen
minutes, then a thirty-minute lockout, counted from `login_attempts`. A looser
per-IP limit runs alongside it to catch password spraying across many accounts —
looser because an office behind one NAT address is a legitimate reason for
several admins to fail at once.

**Every authentication failure gives the same answer.** Wrong password, no such
account, deactivated, never claimed — one message, one status, and
`PasswordHelper::verify()` burns equivalent work against a decoy hash when there
is no account, so the response time does not give it away either.

## API reference

`docs/openapi.yaml` — OpenAPI 3.1, covering every implemented route with request
and response schemas, both auth schemes, and the §9.1 envelope. Import it into
Postman or Insomnia rather than reading it.

Rendered at **`/api/v1/docs`**. Open outside production; in production it needs
`?token=` matching `DOCS_TOKEN`, and returns **404** without one — a 401 would
confirm the endpoint is there. **No `DOCS_TOKEN` means closed**, not open, so a
deployment that never read this file does not publish its own attack surface.

**It is maintained by hand and therefore drifts** — there is no swagger-jsdoc for
PHP without an annotation library and a build step, and this project deploys by
uploading files. So `php bin/openapi.php check` compares the spec against the
live routing table **in both directions**: a route with no entry, or an entry
with no route, fails. It runs in `composer check` and in CI, so drift breaks the
build on the commit that caused it.

That is not theatre — it caught the `/docs` routes themselves within a minute of
my adding them.

`symfony/yaml` parses the spec for that check and is a **dev dependency only**;
production never loads it, and the docs endpoint serves the file as raw YAML
rather than converting it.

---

## Rate limiting and transport security

§14.2 asks for three per-IP limits. Two are here; the third — admin login, five
failures per email then a lockout — lives in `AdminAuthService` because it counts
*failures* rather than requests.

| scope | limit | where |
|---|---|---|
| global | 100 / 15 min | `RateLimit`, global middleware |
| public form | 5 / hour, per form | `ThrottleForm('enquiry')`, per route |

**The counter is a database table, and that is the whole point.** PHP-FPM hands
each request to whichever worker is free, and those workers share nothing — an
in-memory counter sees a fraction of the traffic and the limit silently never
fires. This is the piece the Node → PHP change hurt most (doc §19, deviation 2);
`express-rate-limit` had one long-lived process to count in, and there is no such
process here.

`rate_limits` did not exist in §8 — the document required database-backed limits
without defining the storage. Added as migration 008 and recorded as deviation 7.

Two exemptions, both deliberate: **`OPTIONS`**, because a preflight is the
browser asking permission and counting it would halve every cross-origin
client's budget; and **the health endpoints**, because an uptime monitor polls
them on a schedule and throttling it produces exactly the alert it exists to
avoid.

**The limiter fails open.** If the counter is unreachable the request proceeds. A
guard rail that becomes a wall when it breaks is worse than the thing it guards
against.

Every response carries `X-RateLimit-Limit`, `-Remaining` and `-Reset`, and a 429
adds `Retry-After`. All four are in the CORS exposed-headers list, or the browser
hides them from JavaScript and a client cannot slow down before it is refused.

### The other guards

**`GuardQueryParameters`** rejects array-valued query parameters. PHP turns
`?status[]=A&status[]=B` into an array where every caller expects a string —
`(string) $array` emits "Array", `strlen()` throws — and none of it is visible to
a reviewer reading `$request->query('status')`. No endpoint takes an array today,
so rejecting them outright is correct now and will need relaxing per-route the
day one genuinely wants one.

**HTTPS is refused, not redirected**, in production. A 301 on a POST drops the
body in some clients, and by the time the redirect is issued the credentials in
that request have already crossed the network in clear text. The front-ends
redirect in their own `.htaccess`, which is where a browser-facing redirect
belongs.

**The API's CSP is `default-src 'none'`** and that is not an oversight. §14.2's
permissive policy — Cloudinary, Google Fonts, Maps, Identity — describes what a
*browser* loads while rendering a page, and this API returns JSON. That policy
ships as **`deploy/frontend.htaccess`**, ready to drop into both front-end
document roots. Give it to the front-end developer.

### One thing found while building this

MySQL's `NOW()` was six hours ahead of the application's clock: the app writes
UTC strings built in PHP, and the MySQL session inherited the machine's
Asia/Dhaka timezone. Nothing was broken, because the application never mixed the
two — but any query comparing a stored timestamp against `NOW()` would have been
silently wrong. `Database::connection()` now pins the session to `+00:00`.

---

## The site profile

`site_profile` is a singleton pinned by `CHECK (id = 1)` — the row that replaced
the `brands` table when the project dropped to one site (doc §6). Everything the
header, footer, theme and contact page need lives in it.

`GET /public/layout` composes it with the navigation into the one call the
front-end boots from: site identity, logos, theme colours, contact block, map,
footer copy, grouped menus, social links, newsletter visibility. **No
authentication, no header, no query string** — under v2.0 this is where
`X-Brand` would have gone, and its absence is the observable part of the
single-site cut.

Three things that are deliberate:

- **Nothing inserts.** The row is created once by the seeder; every later change
  is an `UPDATE … WHERE id = 1`. The CHECK constraint is the backstop for a
  mistake this code does not make, not the mechanism.
- **The theme is data, never constants.** §18.2 requires the client to change
  colours from the admin panel with no deployment, so the hex values travel in
  this payload and the front-end applies them as CSS custom properties. A
  hard-coded colour anywhere in the stack breaks that.
- **Optional fields come back as `null`, not as zero.** Coordinates especially:
  `(0, 0)` is a real place in the Gulf of Guinea, and a map centred there is
  worse than one the front-end knows to hide.

Images are returned as `{id, url, alt}` — the public site needs a URL to render,
the admin panel needs the id to change it, and returning one would force the
other consumer into a second request.

`PATCH /admin/site-profile` writes through an allowlist with per-field
validation (hex colours, email addresses, coordinate ranges), and is
Super-Admin-only via `RequireRole::write(Capability::SETTINGS)`.

---

### Authorisation — the §7.3 matrix

**Role is the only dimension** (doc §7.4). Under v2.0 access was the
intersection of role and brand; with one site the intersection is just the role,
so `RequireBrandAccess` and `admin_brand_access` are gone.

Every admin route carries two middleware, in this order:

```php
[RequireAdmin::class, RequireRole::write(Capability::PRODUCTS)]
```

`RequireAdmin` establishes *who*; `RequireRole` decides *what*, against
`RolePolicy` — the §7.3 table written out as data. A route registered with
`RequireAdmin` alone is reachable by every admin of every role, which is correct
for `/auth/admin/me` and almost nothing else.

Four access levels, totally ordered: `NONE < READ < OWN < WRITE`. **A capability
missing from a role's row is NONE** — deny is the default, so adding a
capability without deciding its permissions locks everyone out rather than
letting everyone in.

`OWN` exists for exactly one cell: an Editor may delete media they uploaded, not
media somebody else did. That is a row-level rule a middleware cannot decide, so
`RequireRole::own()` admits the request and records `row_scope` (`all` or `own`)
on it — **and the service is then obliged to filter**. Using `own()` anywhere
else means inventing a rule the document does not contain.

`GET /auth/admin/me` returns the caller's whole matrix row as `permissions`,
served from the same constant the middleware enforces. §7.3 says the dashboard
hides unavailable navigation but the API is the source of truth; sending the row
is what stops a hidden button and a 403 from disagreeing. It is a convenience
for the UI, never a substitute for the server-side check.

### Customer sign-in

Customers use Google only — no password, and no registration endpoint: the
account is created by the first successful sign-in. The browser gets an ID token
from Google Identity Services and posts it to `POST /auth/customer/google`.

**That token is attacker-controlled input** until every check in
`GoogleIdTokenVerifier` has passed. Each one maps to an attack:

| Check | Without it |
|---|---|
| RS256 signature against Google's JWKS | anyone can write their own token |
| `alg` from our list, not the token's | `alg: none`, and HS256 confusion |
| `iss` is Google | a token from any other issuer |
| **`aud` is our client id** | **a real Google token minted for someone else's app** |
| `exp` / `iat` | replay of an old token |
| `email_verified` | claiming an address you do not own |

The `aud` check is the one that gets left out, and it is the one that would turn
every other Google-enabled site's login into ours.

**Account resolution order is load-bearing**: `google_id` first, then `email`,
then create. Matching on email at all is only safe because an unverified address
was already rejected; reversing the two would hand an account to whoever last
used the address rather than to the Google account that owns it.

Google's signing keys are cached on disk with the TTL from their own
`Cache-Control` header (186 ms → 0.1 ms). A key id that is not in the cache
triggers exactly one forced refetch before the token is rejected — an unknown
`kid` is far more often a rotation than a forgery.

**Local development caveat.** `SameSite=None` requires `Secure`, which requires
HTTPS. Over `http://localhost` the cookie would be dropped, so `Cookie::secure()`
falls back to `SameSite=Lax` when `APP_URL` is not https. Cross-origin refresh
therefore cannot be exercised locally — that is a staging check (RTPP-82). Also
leave `COOKIE_DOMAIN` empty locally: a `.rajdhanifood.com` value means the
browser discards the cookie on `127.0.0.1`.

---

## Categories (Phase 2, RTPP-18)

`GET /public/categories` — active categories with a live product count, feeding
the sticky filter bar and the header Products dropdown. No auth, no query
string. `/admin/categories` has the full CRUD set plus `PATCH .../reorder`,
gated on `Capability::PRODUCTS`.

**The slug is globally unique** — there is no brand to scope it by (doc §6).
Two layers enforce that on purpose: the service pre-checks for a clean `409`,
and `uq_categories_slug` catches the race a pre-check cannot (two admins saving
the same new category at once). Neither layer is redundant with the other.

**An explicit slug and a derived one behave differently, deliberately.** Give
`slug` yourself and a collision is a `409` — you chose a URL, not a suggestion.
Omit it and one is derived from `name`, auto-suffixed (`-2`, `-3`, …) on
collision, since nobody chose a specific value to be surprised about.

**Never a hard `DELETE`.** `products.category_id` has a plain foreign key into
this table with no cascade, so `DELETE /admin/categories/:id` sets
`deleted_at` and stops there — a category's products are completely
unaffected. Idempotent: deleting twice, or an id that never existed, still
returns `200`.

### Three real bugs this module found

Not design notes — each failed a live request before it was caught, in order:

1. **A duplicate PDO placeholder in `softDelete()`.** `PDO::ATTR_EMULATE_PREPARES`
   is `false`, so `:now` cannot bind to two spots in one statement — every
   delete returned `500 Invalid parameter number`. Fixed by naming the two
   uses separately.
2. **`is_active: false` failed the same way, one layer down.**
   `PDOStatement::execute($array)` binds every value as a string, and PHP's
   `(string) false` is `''`, not `'0'` — MySQL's strict mode then refuses that
   empty string for a `TINYINT` column. `true` hides the bug (`'1'` parses
   fine), which is exactly why a quick manual test would miss it. **Fixed in
   the base `Repository` class**, not just here: every parameter array is now
   passed through a cast that turns any PHP `bool` into `0`/`1` before
   binding, so no future repository can reintroduce it. Existing repositories
   already routed around this by writing `$bool ? 1 : 0` at the call site;
   this is the same fix, made once.
3. **An explicit duplicate slug on create was silently renamed**, not
   rejected — the first version ran a given slug through the same
   auto-suffix path as a derived one. Fixed by branching: derived slugs
   auto-suffix, explicit ones get an exact match or a `409`.

All three are regression tests in `tests/Feature/CategoryTest.php`, not just
fixed — a test that only exercises the happy path would not have caught any of
them.

---

## Products (Phase 2, RTPP-19)

"The largest content module" in the plan — a product plus three child
collections: pack sizes, highlights, images. `/admin/products` has the same
CRUD-plus-reorder shape as categories; each child collection is *also* its own
addressable sub-resource — `/admin/products/:id/pack-sizes` and siblings —
with the identical shape again, for the dashboard's "add one row" interactions.

**A create or update writes the product and every child in one transaction.**
Send `pack_sizes`, `highlights` and `images` inline and the whole call
succeeds or none of it is saved — this is how a tabbed form is expected to
submit: the current contents of every tab, not an edit script. A child array
is only touched when its key is present in the body at all; omitting
`pack_sizes` leaves them untouched, sending `"pack_sizes": []` clears them —
the two are deliberately not the same thing.

**Rich text is sanitised server-side before storage** (`app/Helpers/RichText.php`,
HTMLPurifier — a genuinely new dependency this module needed). `description`,
`ingredients`, `nutrition_info`, `brewing_guide` and `packaging_info` go
through a small fixed allowlist; `<script>`, `<style>`, `on*` attributes,
`javascript:` URIs and inline images are stripped outright, not escaped.
Verified against real payloads, not just configured and trusted: a `<script>`
tag, an `onerror` handler, and a `javascript:` href were all tested and all
neutralised before this was relied on. An empty or whitespace-only tab is
stored and returned as `null`, never `""` — that is the difference between a
front-end hiding a tab and rendering one that is blank.

**`discount_percent` is never accepted from the client.** It is derived from
`price` and `compare_price` on every write a pack size makes — a client-
supplied percentage could disagree with the two prices next to it, which is a
support ticket waiting to happen. A partial update touching only one of the
two prices still recomputes correctly: the untouched side is read back from
the stored row, not treated as absent.

**`sku` is unique globally**, across every product, not scoped to one — same
two-layer enforcement as a category's slug: a service pre-check for a clean
`409`, `uq_pack_sizes_sku` for the race a pre-check cannot catch.

**A product's children are never referenced by id from outside this module**
(unlike categories, which `products.category_id` points at) — nothing FKs onto
a pack size, a highlight or a product image, and `product_enquiries.pack_size_label`
is a plain text snapshot, not a reference. That single fact is what makes
delete-and-reinsert the *correct* semantics for a whole-array replace, not
merely a convenient one.

**Deleting a product never cascades to its children.** A soft delete sets
`deleted_at` and stops there; the pack sizes, highlights and images survive
completely untouched, because unpublishing a product for a day must not be the
same operation as destroying its catalogue data.

### Two real bugs this module found

1. **A validation failure on a child silently left the product row committed
   anyway.** `create()`'s transaction wrapper skipped `beginTransaction()`
   whenever it detected an already-open transaction — reasonable-looking logic
   copied from `RateLimitRepository`'s much simpler case, wrong here: a test
   harness (or a future bulk-import job) legitimately opens its own outer
   transaction, and skipping this method's *own* transaction inside one
   silently discarded the atomicity the whole method exists to provide. Fixed
   with `SAVEPOINT` / `ROLLBACK TO SAVEPOINT` instead of a bare skip — correct
   whether this runs at the top level or nested inside someone else's
   transaction, with the same code path either way.
2. **A partial pack-size update could silently zero out an existing
   discount.** Caught in review, before it ran once: the first draft's "read
   the untouched sibling price back" logic was a stub that always returned
   `null`. Sending `{"price": 480}` against a pack size that already had a
   `compare_price` would have dropped `discount_percent` to `null` instead of
   recomputing it — reproduced and fixed before the file was ever executed.

Both are regression tests in `tests/Feature/ProductTest.php`.

---

## Public catalogue (Phase 2, RTPP-20)

`GET /public/products`, `GET /public/products/:slug` and
`GET /public/products/:slug/related` — a read-only layer over the same
`products` tables RTPP-19 built, narrowed to `PUBLISHED`, non-deleted rows
and reshaped into two purpose-built views (`ProductService::cardView()` and
`::publicDetailView()`) rather than the admin shapes with fields hidden: no
`status`, `view_count`, or timestamps leak into a public response, and a
`DRAFT` or `ARCHIVED` slug 404s exactly like one that never existed — the two
cases are deliberately indistinguishable from outside.

**Every filter is a plain query parameter** — `category` (a slug, not an id),
`search`, `featured`, `sort` (`newest`, `name`, `price_asc`, `price_desc`,
`rating`) — so the customer site can sync the whole filter state to the URL
and get a shareable, back-button-safe listing (doc §10.2). An unrecognised
`sort` value degrades to the default instead of `422`ing the page: a stale or
hand-edited query string should never be able to break the listing.

**The product card needs a single price and a single image, and neither
column exists on `products`** — a product's price lives on its pack sizes, its
images on `product_images`. `ProductRepository::publicPaginate()` resolves
both with a `ROW_NUMBER() OVER (PARTITION BY product_id ...)` window function
per child table, joined once, rather than a correlated subquery per row: one
pass over each child table for the whole page instead of N, which is what
keeps the query flat as the catalogue grows (the p95 target in doc §14.1).
The picked pack size is the one marked `is_default`, or the cheapest if none
is; the picked image is the one marked `is_primary`, or the first by
`sort_order` if none is. A product with no pack sizes yet reports a `null`
card price rather than erroring, in either sort direction.

**Related products are scoped to the anchor's category, excluding itself,**
published-only, ordered the same `sort_order, name` as everywhere else. A
related-products lookup 404s under the identical rule as the detail route: a
strip cannot exist for a product the customer site could never have reached.

No bugs found in review this time — the schema, output-shaping and
transaction patterns this module needed all already existed from RTPP-18/19;
this was assembly, not new mechanism. Covered by
`tests/Feature/PublicProductTest.php` (20 tests): published-only enforcement
on both listing and detail, every filter individually and combined, every
sort including the no-pack-size and unrecognised-value edge cases, pagination
totals under a filter, the full detail shape, and related-product scoping.

---

## Media (Phase 2, RTPP-21)

`POST /admin/media/signature` and `POST /admin/media` — the two-step,
direct-to-Cloudinary upload from doc §12. The file never transits this
process at either step: `signature()` signs a folder and a timestamp for the
browser to upload with directly, and `register()` only records what
Cloudinary's own upload response reported. This matters more here than on a
VPS — shared hosting caps `upload_max_filesize` and `max_execution_time`, and
neither is ours to raise (doc §16.6).

**What "signed" buys, precisely, and what it does not** (`app/Helpers/CloudinarySigner.php`
carries the full reasoning). `signature()` signs exactly `folder` and
`timestamp`; Cloudinary rejects the upload outright if the browser adds any
other signed-looking parameter, which is what stops a client from asking
Cloudinary to write outside the folder this API chose. `register()` then
verifies `public_id`/`version`/`signature` the same way Cloudinary's own SDKs
do (`verifyApiResponseSignature`) — proving the asset genuinely exists in this
account at that version. That is *not* a signature over `bytes`, `format`, or
the dimensions; Cloudinary's protocol was never designed to make those
tamper-proof against the account's own authenticated admins, and this module
does not pretend otherwise. Given a verified `public_id`, the `folder` a card
is filed under is derived from it directly, never from a client-sent `folder`
field — the one thing worth trusting is what Cloudinary's own identifier
implies.

**Registration enforces every §12 limit that matters:** images ≤ 5 MB as
JPG/PNG/WebP/SVG, documents ≤ 20 MB as PDF only — checked against the
`format`/`bytes` Cloudinary's response carries, both a genuinely reachable
`400 UPLOAD_FAILED` rather than a client-side-only check. The same `public_id`
cannot be registered twice (`409`), and a `public_id` outside the configured
folder prefix is refused — structurally redundant given how `signature()`
constrains what Cloudinary will hand back, but cheap, and a real check rather
than decoration.

**Gated at `RequireRole::own(Capability::MEDIA)`, not `write()`** — the one
detail this module got wrong on the first pass, caught before any test ran:
§7.3 gives an Editor only `OWN` on `media` (`WRITE` is Super Admin only), and
`own()` is exactly "holds at least `OWN`, or `WRITE` above it" — the correct
threshold for "may create an asset at all". `write()` would have locked every
Editor out of uploading anything, for every module that needs an image.
Creating a new asset never needs the row-scope filtering `own()` also
records on the request; there is no "someone else's row" to distinguish from
when the row being created is the caller's own by construction. That
filtering is what RTPP-22's delete route will actually consume.

Verified against the real Cloudinary account named in this repo's `.env` (not
a mock): a genuine file upload through the signed parameters, then
registered through this API, round-trips end to end — plus live checks for
both DoD rejections (a forged signature, an oversized file) and the
duplicate-registration conflict. All three are also covered without touching
the network in `tests/Feature/MediaTest.php` (12 tests) — the signing and
verification are pure cryptography, testable with the account's real secret
from `.env` but no HTTP call to Cloudinary, so the whole rejection surface has
automated coverage that never depends on network access.

### Media deletion (Phase 2, RTPP-22)

`DELETE /admin/media/:id` — checks every table with a foreign key onto
`media_assets.id` before deleting anything, and only ever deletes both the
registry row and the Cloudinary asset together. Ticket text originally named
"brand logos" as one of the tables to check — a two-brand-era holdover, fixed
before implementation to the real FK set pulled from the migrations:
`admin_users`, `categories`, `product_images`, `site_profile` (four columns),
`seo_meta`, `gallery_categories`, `gallery_images`, `news_posts`, `downloads`,
`banners` (two columns), `feature_items`, `process_steps`, `certifications`
(two columns), `testimonials`, `page_blocks` — twenty FK columns across
fifteen tables, all in `MediaRepository::REFERENCE_CHECKS`, which is
deliberately data rather than one hand-written query per table so the list
stays the single place to update when a future migration adds another FK.

**A referencing row counts regardless of its own status** — a soft-deleted
category still holds its `image_id` (soft delete never clears it), and
"might come back" is what soft delete means everywhere else in this
codebase. Verified live: deleting the category that referenced a test asset
left the asset still blocked (`409`, still naming `categories`) until the
reference itself was cleared.

**Ordering is the whole safety property.** The registry row is deleted
*before* Cloudinary's own asset, inside a transaction, using the
`HandlesTransactions` trait extracted from `ProductService` once this module
needed the identical SAVEPOINT-safe wrapper. If the Cloudinary call then
fails, the row comes back with it — the reverse order would risk exactly the
failure this ticket exists to prevent: a registry row surviving after
Cloudinary has already deleted the file, which is a broken image the moment
anything renders it. A regression-shaped test
(`testACloudinaryFailureRollsBackTheRegistryDeletion`) forces that failure
and asserts the row is still there afterward, not just that an error was
thrown.

**Gated at `own()`, consumed for the first time.** RTPP-21 registered routes
at this same threshold but never exercised the row-scope half of it — nothing
yet needed to tell "my own upload" from "everyone's". This ticket is the
route `own()` was actually written for: `MediaService::delete()` reads the
`row_scope` middleware attribute and refuses (`403`) an Editor deleting
someone else's upload, while a Super Admin (`row_scope: 'all'`) may delete
anything. Verified live with two distinct Editor tokens — Editor B blocked
from Editor A's upload, Editor A permitted on their own.

**One real network dependency, isolated behind an interface.** Deleting a
file cannot be verified without actually asking Cloudinary to delete it, so
unlike registration this is not something pure cryptography can stand in
for. `CloudinaryDestroyer` (interface) / `CloudinaryAssetDestroyer`
(implementation, over the shared `HttpClient` used elsewhere for Google's
JWKS) follows the same pattern as `JwkSource`/`GoogleJwkSource` — the 8 new
tests inject a recording fake, and the one thing that genuinely cannot be
faked (does Cloudinary actually delete the file) was proven live instead: a
real upload, delete, and a follow-up request to the asset's own `secure_url`
returning `404` — not just a database check.

**Scope decision, made deliberately, not by oversight:** the ticket's
"nightly orphan sweep job" bullet is not implemented here. It appears only
under the ticket's "Scope" list, not its "Done when" criteria, and it
requires job-scheduling infrastructure (`app/Jobs/` is presently an empty
directory — no runner, no cron-HTTP entrypoint, nothing to hang a job on
yet) that doesn't exist anywhere else in this codebase. Building a generic
job runner as a side effect of a media-deletion ticket would be a scope
expansion beyond what this ticket asks for signed off on; it belongs in its
own ticket once job infrastructure is actually needed; the DoD ("deleting an
in-use asset 409s naming what uses it" / "no broken image URL is reachable
after a delete") is fully met without it.

---

## Banners (Phase 2, RTPP-23)

`/admin/banners` (CRUD plus reorder) and `GET /public/banners?placement=...`
— flat, no child collections, but two things keep it from being a copy of
`CategoryService`.

**`sort_order` is scoped to one `placement`, not global.** A `HOME_HERO`
slider and a `SIDEBAR_AD` slot never render together, so nothing enforces a
single ordering across all fourteen placements. `POST /admin/banners/reorder`
takes `{placement, ids}` rather than a bare id list, and its `UPDATE`
includes `WHERE placement = :placement` on every row — an id from a
different placement affects zero rows and is reported as not belonging,
never silently given a position in the wrong slider. Verified live: a
two-banner reorder within one placement lands exactly where sent, and a test
proves an id from another placement is rejected while the valid id in the
same call still applies.

**The schedule window is validated as a pair, and against the *stored* other
side on a partial update.** `starts_at`/`ends_at` accept anything
`DateTimeImmutable` can parse, normalised to UTC and this schema's
`DATETIME(3)` text shape so a plain string comparison agrees with
chronological order. `{"ends_at": "..."}` sent alone is checked against the
row's existing `starts_at`, not treated as if that side were absent — the
same class of bug RTPP-19 found in `ProductService`'s partial pack-size
discount recompute, guarded against here from the start rather than found
by a failing test.

**Banners default to `status: PUBLISHED`, not `DRAFT`** — the schema's own
default, and the right one: an admin creating a hero banner is almost always
putting it live immediately, unlike a product, which is drafted while its
content is still being filled in.

Verified live against the running dev server: a banner scheduled for
tomorrow does not appear in today's public listing (the DoD, literally), and
reordering two banners is reflected in the very next request with no
deploy — the second half of the DoD. 37 tests across
`tests/Feature/BannerTest.php` (admin CRUD, validation, reorder) and
`tests/Feature/PublicBannerTest.php` (schedule window, placement filtering,
ordering), the latter against a database that already carries real seeded
banners for several placements from earlier phases — tests needing an exact
count use an unseeded placement (`MID_PAGE_CTA`) rather than assuming an
empty table, the same lesson RTPP-20's public catalogue tests already
learned. No bugs found in review — reviewed against the two known failure
classes from this phase (transaction nesting, a partial-update stub reading
the wrong default) and guarded against the second from the start, as above.

---

## Content module (Phase 2, RTPP-24)

Six flat, sortable resources — the module that makes the marketing pages
editable without a deployment: `feature_items`, `process_steps`,
`stat_counters`, `certifications`, `testimonials`, `page_blocks`. Each gets
its own repository and service rather than a shared "generic CRUD"
abstraction — consistent with every other module in this codebase, and the
six differ in enough small ways (grouping column or none, `is_active` vs
`status`, one genuine uniqueness constraint, one rich-text field) that a
shared abstraction would have grown special cases faster than it saved code.

**None of the six tables has a `deleted_at` column.** Nothing in the schema
references any of their ids, so `delete()` on every one of them issues a
real `DELETE`, not a soft one — there was no soft-delete column to have used
instead, and adding one nothing reads would be complexity with no reader.

**No pagination.** The admin dashboard describes these as "a sortable list"
(doc §11), not a paginated table, and every group in practice holds a
handful of rows. `list()` returns everything (optionally filtered by the
resource's own grouping column) rather than a `{data, meta}` envelope.

**Two are genuinely grouped-and-scoped, matching the `BannerRepository`
pattern**: `feature_items` (by `section`) and `stat_counters` (by `group`)
scope both filtering and reorder to one group at a time, the same reasoning
as a banner's placement. **Two are flat**: `certifications` and
`testimonials` reorder as one unscoped list, like categories. **Two carry
their own uniqueness constraint** — this ticket's two headline DoD items:

- **`process_steps`**: `UNIQUE (group, step_number)` — new in v3.0, "impossible
  to enforce cleanly" (the ticket's own words) while the key had to include
  `brand_id` too. Two layers, the same pattern as a category's slug: a
  service pre-check for a clean `409`, `uq_process_steps_group_number` for
  the race a pre-check cannot catch. A test bypasses the service entirely,
  inserting directly via SQL, to prove the constraint itself rejects the
  collision — not just the application-level guard in front of it. A partial
  update touching only `step_number` is checked against the step's own
  *stored* `group`, applying the RTPP-19/23 lesson before it could be found
  the hard way again.
- **`page_blocks`**: `UNIQUE (page_key, block_key)`, identical two-layer
  treatment, identical direct-SQL proof test.

**`page_blocks.body` is this module's one genuinely rich-text field** —
`MEDIUMTEXT`, commented as such in the schema — sanitised through
`RichText::sanitize()` before storage, the DoD's other named item, verified
live with a real `<script>` payload (stripped; the surrounding real content
survived intact). The other `TEXT` fields across all six tables
(`feature_items.description`, `process_steps.description`,
`testimonials.quote`) are short descriptive prose in every example the doc
gives them, not documented as rich text anywhere in the schema comments the
way `page_blocks.body` is — they are validated as plain text, not run
through HTMLPurifier. This is a scope decision, not an oversight: the DoD
names "rich text," singular treatment, not "every text field."

**Scope decision:** this ticket builds admin CRUD only. Nothing here is
exposed over `/public/*` — the DoD ("every ... row maps to a database row,"
sanitisation, the uniqueness constraint) is entirely about the CMS side, and
actually rendering this content on public marketing pages implies
page-aggregation endpoints (a `/public/about`, a `/public/home` assembling
banners plus stats plus testimonials plus feature items together) that do
not exist as their own ticket yet. Building them here would be scope
creep into a concern this ticket's DoD never named.

Verified live against the running dev server (a real database that already
carries real seeded rows across every one of these six tables from earlier
phases — tests needing an exact count use an unseeded group/section/page_key
throughout, the same lesson RTPP-20's and RTPP-23's tests already learned):
a duplicate `(group, step_number)` correctly `409`s, a duplicate
`(page_key, block_key)` correctly `409`s, and a `<script>` payload in a page
block's body is stripped while real content survives. 50 new tests across
six files. No bugs found — reviewed against every failure class this phase
has actually produced (transaction nesting — not applicable, nothing here
needs a transaction; a partial-update stub reading the wrong default —
designed around from the start for both uniqueness constraints).

---

## Gallery (Phase 2, RTPP-25)

`gallery_categories` (the tab bar) and `gallery_images` (the grid), plus
`/public/gallery/categories` and `GET /public/gallery` (doc §9.5) — the first
module in Phase 2 with real public-facing endpoints beyond banners.

**The ticket text needed a correction before implementation.** It described
categories as "brand-scoped with slugs" and images as carrying "caption, alt
text" — both stale. The schema (the v3.0 single-site cut) has no `brand_id`
anywhere in either table; a slug is globally unique, the same reasoning as
`CategoryService`. `gallery_images` carries `title`/`description` columns,
not a dedicated alt-text field — the resolved `alt` text a public image card
returns comes from the *media asset* it points at (`media_assets.alt_text`),
the same as every other image reference in this codebase. The ticket's
"Blocked by §19" note was also already resolved elsewhere in the same
document, dated the same day: §19 records "Events/Team gallery landing
pages? **Filters only**, no dedicated pages" as settled under RTPP-17,
which is what this ticket implements regardless — a filter query parameter,
never a per-category route.

**Neither table has any timestamp columns**, a first for this codebase: no
`created_at`/`updated_at` on `gallery_categories` at all, and only
`created_at` (no `updated_at`) on `gallery_images`. Not a gap to patch —
simply what the schema declares, so the repositories mirror it exactly
rather than inventing columns nothing reads.

**Deleting a category is a genuine, cascading hard delete — the one new
delete-behaviour shape in this whole session.** Every prior module either
soft-deletes or hard-deletes a row nothing references. Here,
`fk_gallery_images_category` is declared `ON DELETE CASCADE` in the schema
itself (doc §8.7's migration), so a category's images share its lifetime by
design. `DELETE /admin/gallery/categories/:id` respects that rather than
guarding against it, and `GalleryCategoryService::imageCount()` exists so
the admin screen can warn how many images are about to go, before the
confirmation, not after. Verified live: deleting a category with two
registered images removed both the category row and every image row in the
same call, confirmed directly against the database.

**Images require `media_id`, unlike every other optional media reference in
this codebase.** `gallery_images.media_id` is `NOT NULL` in the schema — a
gallery image *is* the media it points at, so it is a required field
end-to-end (`GalleryImageService::requiredMediaId()`), not the
`optionalMediaRef()` pattern banners, certifications, and page blocks all use.

**Bulk registration**, the scope item literally named in the ticket for the
admin bulk-upload screen: `POST /admin/gallery/images/bulk` takes a
`category_id` and a list of `{media_id, title?, description?}` entries
already uploaded through the RTPP-21 sign-then-register flow, and registers
all of them in one call, appended after whatever `sort_order` the category
already has. Every entry is validated — including that each `media_id`
resolves to a real asset — before any row is written, inside a
`HandlesTransactions`-wrapped transaction: a batch with one bad entry writes
nothing rather than leaving the admin unsure which of several uploads
actually landed. Verified live: a two-entry batch registered both images at
the next two sort positions after an existing one at position 5 (landing at
6 and 7), and a batch with one invalid `media_id` was rejected whole —
zero rows written, confirmed by re-listing the category afterward.

**Reorder scoping mirrors banners for images (scoped to one `category_id`)
and categories for the tab bar (flat, unscoped, like `CertificationService`)**
— the same split RTPP-24 already established between grouped and flat
resources, applied here to a genuinely dynamic group (a category id) rather
than a fixed enum.

**Scope decision on public output:** `PublicGalleryImage.category` includes
the owning category's id/name/slug on every image, not just its id — the
public grid can show "All" (interleaving every category by the category's
own `sort_order`, then the image's) without a second round trip to resolve
which category each card belongs to.

Verified live against the running dev server: category CRUD, bulk
registration (success and atomic-rejection cases), the cascading delete (at
the database level, not just the API response), and both public endpoints
— the tab bar reporting live image counts and the filtered feed returning
only active images in active categories. 25 new tests across
`tests/Feature/GalleryCategoryTest.php`, `tests/Feature/GalleryImageTest.php`,
and `tests/Feature/PublicGalleryTest.php`, the latter two using fresh
category names/slugs throughout — the seeded database already carries four
real gallery categories (Tea Gardens, Manufacturing, Events, Team) with
images in three of them, the same lesson RTPP-20/23/24 already learned. No
bugs found — reviewed against every failure class this phase has produced
(transaction nesting: guarded against from the start via the shared
`HandlesTransactions` trait, the same as `MediaService`; a partial-update
stub reading the wrong default: not applicable, neither table has a paired
field like a schedule window or a composite key).

---

## News (Phase 2, RTPP-26)

`news_posts` — admin CRUD, `GET /public/news` (paginated, optional `tag`),
`GET /public/news/featured` (the home "Latest Updates" band), and
`GET /public/news/{slug}` (full article, view-count increment, prev/next
navigation).

**Ticket text needed a correction before implementation.** It described the
slug as "unique per brand" — stale; the schema (single-site cut) has no
`brand_id` anywhere, and `uq_news_posts_slug` is a plain global unique key,
the same reasoning as `CategoryService`. The ticket's own "§19 item 5" note
(whether a public News page is required at launch, flagged as needing
confirmation before Phase 4) was already resolved elsewhere in the same
document: **not required**, no design was ever supplied, so it drops from
Phase 4 scope. That resolution is about the customer-facing page, not this
ticket — the backend API is unaffected and was built in full regardless.

**Publishing is a status plus a timestamp, not just a status.** Setting
`status: PUBLISHED` with no `published_at` publishes immediately —
`NewsService::effectivePublishedAt()` defaults it to "now" the moment the
*effective* status (whichever a call sends, or the stored one on a partial
update) resolves to `PUBLISHED` and no timestamp is otherwise available, the
same "effective value" reasoning `BannerService`/`ProcessStepService` apply
to their own paired fields. An explicit future `published_at` schedules the
post instead — the repository's visibility filter
(`status = 'PUBLISHED' AND published_at <= NOW()`) is what actually enforces
the DoD ("invisible publicly until that date"), computed at query time, no
job required. Verified live: a post published with no date landed at the
current timestamp and appeared immediately in the public listing; a post
scheduled for 2030 did not appear in the listing and its detail route
404'd, identically to a slug that never existed.

**`content` is sanitised rich text** (`MEDIUMTEXT`, schema-commented as
such) through `RichText::sanitize()`, the same treatment as a page block's
`body`; `excerpt` and `meta_description` are plain `TEXT`, validated as
plain strings — the same "rich text means the field actually named as such"
reading RTPP-24 established. A post whose entire `content` is disallowed
markup (nothing survives sanitisation) is rejected outright rather than
silently stored as an empty article body. Verified live with a real
`<script>` payload: stripped, the surrounding real content intact.

**`author_id` is never client-supplied.** It is stamped from the
authenticated admin at creation and cannot be changed through this API —
tested explicitly by sending a spoofed `author_id` in the request body and
confirming the stored value is the caller's own id, not the one submitted.

**Prev/next navigation** (this ticket's second DoD item) walks the same
`published_at DESC, id DESC` order the public listing itself uses: "previous"
is the next *older* visible post, "next" is the next *newer* one, and either
is `null` at its end of the list. A direct-SQL-free three-post chain test
(oldest/middle/newest) proves both ends are `null` in exactly the right
direction and the middle post sees both neighbours correctly.

**`GET /public/news/featured` ignores `is_featured`.** The doc names "three
most recent published news posts," not "three flagged as featured" — the
column stays available (settable through the admin API) but has no bearing
on which three posts this endpoint returns, proven by a test that flags a
post `is_featured: false` and confirms it is still returned when it is one
of the three most recent.

**No `/admin/news/reorder`** — `news_posts` has no `sort_order` column; the
public listing orders by `published_at`, not a manually dragged position.

Verified live against the running dev server: sanitisation, publish-now
defaulting, future-scheduling invisibility (list and detail both), view-count
incrementing on repeated detail requests, and prev/next on a real chain.
20 new tests across `tests/Feature/NewsTest.php` (admin CRUD, scheduling,
sanitisation, slug uniqueness) and `tests/Feature/PublicNewsTest.php`
(visibility, tag filter, featured, prev/next) — the latter archives the
database's one real seeded post (`NewsSeeder`) for the scope of its own test
run, since the boundary assertions ("previous is null at the oldest end")
are only meaningful against a known, closed set of posts, not a table that
already carries a real currently-visible row alongside the fixtures. No
bugs found — reviewed against every failure class this phase has produced;
none applied (no transaction needed, and the status/published_at pairing was
designed around the RTPP-19/23 lesson from the start).

---

## Reviews (Phase 2, RTPP-27)

Customer-submitted product reviews with an admin moderation queue and the
`rating_average`/`rating_count` aggregates denormalised onto `products` —
the first module in this codebase where the *customer*, not an admin, writes
data through a protected route (`RequireCustomer`, already built for RTPP-13
but unused until now).

**Reviews default to `PENDING` and stay invisible until approved** — the
ticket's first DoD item, enforced at the point of creation: `ReviewService::create()`
never lets the caller choose a status, `NewsService::author_id`-style. Verified
live: a review submitted through `POST /public/products/{slug}/reviews`
was absent from `GET /public/products/{slug}/reviews` and did not move
`rating_average`/`rating_count` on the product until an admin approved it.

**One review per customer per product** (`uq_reviews_product_customer`),
enforced two layers deep like every other uniqueness constraint in this
codebase: a pre-check for a clean `409` naming the conflict, the constraint
itself for the race. A customer wanting to change their opinion edits the
existing review (`PATCH /public/my/reviews/{id}`) rather than submitting a
second one.

**Approve and reject are unconditional transitions, not restricted to
`PENDING`.** Doc §8.5 names "rejected *after approval*" as one of three
aggregate-recompute triggers — which only makes sense if `APPROVED →
REJECTED` is a real, supported path, so neither admin action checks the
review's current status first. Editing an approved review also un-approves
it: `ReviewService::updateOwn()` resets status to `PENDING` unconditionally,
and if the review being edited was `APPROVED`, the aggregate is recomputed
in the same transaction as the edit — a review the author just changed can
no longer count toward a number a public page is showing right now.

**The one genuinely new authorisation shape this ticket needed**: doc
§9.10 lists `DELETE /admin/reviews/:id` as **Super Admin** only, stricter
than the plain WRITE level Editor and Super Admin share on the REVIEWS
capability for everything else (approve, reject, bulk actions). §7.3's
matrix has no way to express "WRITE, but only for one role" inside a single
capability — it is deliberately one row per capability — so a new
`RequireSuperAdmin` middleware stacks *after* `RequireRole::write()` on that
one route rather than reshaping the matrix for a distinction the document's
coarse §7.3 row never draws. Verified live: an Editor-role token got a clean
`403` ("Only a Super Admin may do that") on the delete route while
successfully approving/rejecting the same review moments earlier; a Super
Admin token then deleted it and the aggregate recomputed to zero.

**Bulk approve/reject apply what they can and report what they cannot** —
the same contract `CategoryRepository::reorder()`/`BannerRepository::reorder()`
already use for a batch of independent existing rows: a stale id (another
moderator already handled it) does not block the rest of the batch.

**The star-rating distribution the ticket names** ("the distribution used by
the reviews tab") rides in `GET /public/products/{slug}/reviews`'s `meta`
alongside `rating_average`/`rating_count`, zero-filled for every rating 1
through 5 — not duplicated onto the product-detail payload that already
carries the two denormalised numbers, since the reviews tab can load
independently of a full product re-fetch and still have everything it needs.

**Scope decision:** `products.rating_average`/`rating_count` are the only
numbers this ticket denormalises — no `AggregateRating` JSON-LD markup is
generated here. That is a `react-helmet-async` concern on the frontend (doc
§10.2, §14.3); this ticket's job, and the third DoD item, is guaranteeing
those two columns are computed from approved reviews only, which is true by
construction — `ReviewRepository::aggregateForProduct()`'s query has a
`status = 'APPROVED'` clause nothing else touches.

Verified live against the running dev server: unauthenticated submission
rejected with `401`; a submitted-then-approved review correctly moved the
product's aggregate; an Editor blocked from hard delete while still able to
moderate; a Super Admin's delete correctly zeroing the aggregate back out.
22 new tests across `tests/Feature/ReviewTest.php` (admin moderation, the
DoD's "matches a manual count after approve/reject/delete" sequence) and
`tests/Feature/PublicReviewTest.php` (submission, own-review editing/deletion,
public listing). One real bug found and fixed during implementation: the
first draft of `approve()`/`reject()` re-fetched the changed review through
`find()` (the bare row) before shaping it for the admin response, but the
response shaper expects the product/customer-joined shape `paginate()`
returns — caught immediately by the test suite (`Undefined array key
"product_name"`), fixed by adding `ReviewRepository::findWithContext()` for
exactly this call site rather than joining unconditionally inside `find()`,
which several other call sites use precisely because they *don't* need the
join.

---

## Wishlist (Phase 2, RTPP-28)

`wishlist_items` — add, remove, list, and a guest-to-account merge at login,
all customer-token gated (`RequireCustomer`, its second real consumer after
Reviews).

**Ticket text needed fixing before implementation.** It described a
wishlist entry as referencing "a brand-scoped product," requiring the
listing to group by brand — stale; there is no brand concept anywhere in
this schema (`wishlist_items` carries no `brand_id`), so the listing is just
a flat list of product cards, matching doc §9.7's actual route table. The
ticket's scope bullets also never mentioned `POST /public/wishlist/merge`
even though doc §9.7's own route table and §8.6's prose both describe it in
detail — added explicitly rather than silently built or silently dropped.

**Every mutation — add, remove, merge — returns the fresh full wishlist**,
not just the entry that changed. Doc §8.6 says merge specifically "returns
the merged server list"; giving every mutation that same contract means the
client can always just replace its wishlist state wholesale after any one
of the three calls, rather than reconciling three different response shapes
against local state.

**`add()` is idempotent** — the same two-layer pattern as every other
uniqueness constraint in this codebase: a pre-check against
`WishlistRepository::exists()`, `uq_wishlist_customer_product` for the race
a pre-check cannot catch. A product must be currently `PUBLISHED` to be
added through the single add endpoint (a customer can only ever click "add
to wishlist" from a product page they can actually see); `merge()` relaxes
this to "skip rather than reject the whole batch," since a guest's local
wishlist is client-controlled and easily carries a stale id for something
removed since it was saved — failing an entire login-time merge over one
bad entry would be a worse experience than silently dropping it. Verified
live: merging `[validId, "01HZZZZZZZZZZZZZZZZZZZZZZZ"]` returned only the
valid product, no error.

**"Does not leave a dangling wishlist row" is handled the same way every
other soft-deleted reference in this codebase is** — filtered out of the
read path, not purged. Products here are soft-deleted (`ProductRepository::softDelete()`),
which `fk_wishlist_product`'s `ON DELETE CASCADE` cannot see (it only fires
on a genuine `DELETE`, a path this app's admin flow never actually takes).
The new `ProductRepository::publicCardsForIds()` — the same card-building
joins `relatedByCategory()` already uses, reused rather than duplicated —
carries the same `PUBLISHED AND deleted_at IS NULL` filter every other
public listing has, so a wishlisted product that is later unpublished or
soft-deleted simply produces no card; `WishlistService::cardsFor()` drops
any wishlist row with no matching card rather than surfacing a gap. Verified
live: soft-deleting a wishlisted product left the underlying `wishlist_items`
row intact (`SELECT COUNT(*)` still 1) while the listing correctly returned
`[]` — no error, no broken reference, the row simply doesn't render, exactly
mirroring how a soft-deleted category or product is already handled
everywhere else in this codebase.

**One real bug found and fixed while writing tests**: `listForCustomer()`'s
`ORDER BY created_at DESC` alone is not a strict total order — two adds
landing within the same millisecond (which a fast test genuinely triggers,
not a hypothetical) sort arbitrarily against each other. Fixed by adding
`id DESC` as a tiebreaker; a ULID sorts lexicographically by creation time,
so this agrees with true insertion order even when the timestamp's
millisecond precision cannot distinguish two rows.

**"Signing in on a second device shows the same wishlist"** needs no special
handling to satisfy — the wishlist is keyed by `customer_id` alone, with
nothing device- or session-specific in the schema, so it is already
identical on every device the moment the customer authenticates.

Verified live against the running dev server: unauthenticated `GET` rejected
with `401`; add, idempotent re-add, and remove all behaving correctly; merge
skipping a bad id while keeping the valid one; a soft-deleted product's
wishlist row surviving invisibly. 14 new tests in
`tests/Feature/WishlistTest.php`. One real bug found (the ordering tiebreak
above) — reviewed against every other failure class this phase has produced;
none of the rest applied (no transaction needed, no paired field to get
wrong on a partial update).

---

## Enquiries (Phase 2, RTPP-29)

Product enquiries — the platform's primary conversion path, since there is
no cart or checkout (doc §2) — with gapless `RDFP-ENQ-{year}-{seq}`
reference numbers, admin moderation, and CSV export.

**This ticket's design note explains its own headline mechanism**, and it
held up under a real load test: `reference_counters` has one row per
`(type, year)`; `ReferenceCounterRepository::nextSequence()` locks it with
`SELECT … FOR UPDATE` and advances it inside the *same* transaction as the
enquiry's `INSERT`. An `AUTO_INCREMENT` or a native sequence cannot be
gapless because the sequence advances outside the transaction that consumes
it — a rolled-back insert burns the value permanently. Here, if the insert
fails, the whole transaction (counter advance included) rolls back
together, and the next successful submission reclaims the number. Verified
by forcing a real mid-transaction failure (a missing `NOT NULL` column) via
`SAVEPOINT` and confirming the retry got the number the failed attempt
would have taken, not the one after it.

**"Load-tested, not reasoned about"** — the ticket's own DoD wording — is
taken literally: `tests/Feature/EnquiryConcurrencyTest.php` forks 50 real
OS processes with `pcntl_fork()`, each with its own MySQL connection, all
racing to submit at once, then asserts the 50 reference numbers are unique
and form one contiguous run with no gaps. This is deliberately not a
`DatabaseTestCase` — that base class wraps a test in one transaction it
rolls back, which would prevent the very thing being tested (real,
independently-committing concurrent transactions) — so this test commits
real rows to the real database and cleans up manually.

**Two real bugs found by that load test, neither visible from a sequential
test or from reasoning about the code:**
* **A genuine InnoDB deadlock** under 50-way contention on the counter
  row's first-ever creation for a given year: `nextSequence()` originally
  ran `INSERT IGNORE` unconditionally before `SELECT … FOR UPDATE`, so
  every concurrent caller took an insert-intention lock on the same
  not-yet-existing key at once — a textbook gap-lock deadlock. Fixed two
  ways: `nextSequence()` now tries `SELECT … FOR UPDATE` first and only
  falls back to `INSERT IGNORE` when the row is genuinely absent (so the
  overwhelmingly common case — the row already exists — never touches
  `INSERT IGNORE` at all), and `EnquiryService::submitWithDeadlockRetry()`
  retries the whole transaction with a small random backoff on a real
  SQLSTATE `40001`, since MySQL's own documentation is explicit that an
  application must retry a deadlocked transaction. (`RateLimitRepository::hit()`
  has the same unconditional-`INSERT IGNORE` shape and is a plausible
  candidate for the same fix, but that file is out of scope for this
  ticket and was left untouched.)
* **A `fork()`-and-PDO socket-sharing bug in the test itself**: `fork()`
  duplicates file descriptors, not sockets, so a database connection open
  in the parent at fork time is the *same* live TCP session in every child.
  A PDO object's destructor sends a real `COM_QUIT` over the wire — a child
  process exiting while still holding a reference to the parent's
  connection closed the *shared* session, killing the parent's connection
  too ("MySQL server has gone away", reproduced while writing the test).
  Fixed by having the parent process close its own connection before
  forking and not reopening one until every child has exited, so there is
  no shared session to corrupt in the first place.

**Scope decision, made with the user's explicit sign-off before implementation
started:** this ticket does **not** fire the sales notification email. No
mail-sending infrastructure exists anywhere in this codebase yet —
`app/Mail/` is an empty directory, no mail library is installed — and
building the shared PHPMailer-backed sender, the notification matrix across
all eight §14.4 events, and settings-driven recipient resolution is
`RTPP-33`'s entire scope, still `To Do`. A one-off mailer built here would
duplicate work RTPP-33 has to redo properly for the other seven events.

**CSV export is returned as text inside the standard JSON envelope**
(`{"data": {"filename", "csv"}}`), not as a `Content-Type: text/csv` file
download. `Kernel`'s own class doc states a hard invariant — "nothing
leaves this class except a section 9.1 envelope" — for a real security
reason (an unhandled exception must never leak a body outside that
envelope), and extending the response pipeline to carry a second content
type is a framework change this ticket has no mandate to make
unilaterally. The dashboard can turn the returned string into a
client-side download exactly as easily as it would consume a streamed file.

**`product_id` is optional** on submission — the schema allows a general
enquiry not tied to one product, and the ticket doesn't say otherwise.
**Reference numbers, rate-limiting** (`ThrottleForm('enquiry')`, this
form's own 5-per-hour-per-IP budget, already built and simply wired in
exactly as its own docblock's usage example shows) **and optional customer
attribution** (`OptionalCustomer`, so the form pre-fills for a signed-in
visitor without requiring sign-in) round out the submission path.
reCAPTCHA v3 and the honeypot field (doc §9.6) are `RTPP-34`'s separate,
not-yet-built scope — the rate limit is the one protection this ticket can
wire in today.

Verified live against the running dev server: anonymous submission and its
returned reference number, invalid-email rejection, the admin list/update/
CSV-export cycle, and the rate limit itself (5 requests succeed, the 6th
from the same IP is `429`). 11 new tests across `tests/Feature/EnquiryTest.php`
(submission, validation, the rollback-leaves-no-gap proof, admin CRUD, CSV)
and `tests/Feature/EnquiryConcurrencyTest.php` (the 50-process load test,
one test asserting 52 things about the run).

---

## Dealer applications and locations (Phase 2, RTPP-30)

Dealer / distributor applications — the platform's other lead-capture form
(doc §2, §10.3) — plus the district/upazila reference data behind its
cascading location select, with gapless `RDFP-DA-{year}-{seq}` application
ids, admin moderation, and CSV export.

**Reuses `EnquiryService`'s (RTPP-29) exact gapless-id mechanism**, keyed on
`('DA', $year)` instead of `('ENQ', $year)` — same `reference_counters`
table, same `SELECT … FOR UPDATE` locking, same rollback-safety property.
The deadlock-retry wrapper `EnquiryService` needed under real concurrent
load was extracted into a trait, `Services/Concerns/RetriesDeadlocks.php`
(`retryOnDeadlock()`), once this ticket needed the identical wrapper around
the identical `nextSequence()` code path — the same "copying it a second
time is the moment this stops being coincidence" reasoning `ValidatesInput`
was already built on. `EnquiryService` was refactored to use the extracted
trait too, so there is exactly one deadlock-retry implementation in the
codebase, not two copies that could drift.

**No second fork-based load test.** The DoD's "`ENQ` and `DA` counters are
independent — incrementing one never affects the other" is proved directly
in `tests/Feature/DealerApplicationTest.php` by interleaving enquiry and
application submissions and asserting each gets its own gapless run from 1.
The concurrency-safety of `nextSequence()` itself — the thing
`EnquiryConcurrencyTest`'s 50-process fork test exists to prove — is not
re-proven with a second fork test: both callers drive the identical lock on
the identical table, so a second load test would exercise the same code
path `EnquiryConcurrencyTest` already covers, not a new one.

**District/upazila reference data already existed** in
`database/seed-data/bd-locations.json` (64 districts, 493 upazilas,
transcribed from the withdrawn v1 implementation per `LocationSeeder`'s own
doc) and `database/seeders/LocationSeeder.php` was already written and
already wired into `bin/seed.php` — both predate this ticket. This ticket
adds the read side: `LocationRepository`, `LocationService`, and
`GET /public/locations/districts` / `GET /public/locations/districts/:id/upazilas`.
A district id that doesn't exist is `404`, not an empty upazila list, so a
stale front-end dropdown value is visibly wrong rather than silently empty.
`upazila_id` on submission must belong to the given `district_id`, checked
explicitly — a mismatched pair (a real upazila under the wrong district) is
as invalid as a made-up id.

**Scope decision, following the RTPP-29 precedent exactly:** "triggers the
sales email" is deliberately not built here, for the identical reason — no
mail infrastructure exists yet (`RTPP-33`, still `To Do`). `dealer_notify_emails`
(already seeded by `SettingsSeeder`) is the recipient list RTPP-33 will read.

**Expected response window is admin-configurable, not hard-coded.** The
ticket cites doc §18.5 for "the modal payload the design requires," but that
section does not exist — §18 ("Acceptance Criteria") has no numbered
subsections, confirmed by grep — and no exact SLA text appears anywhere
else in the document either. Rather than inventing a string the client
hasn't confirmed, it's a new `settings` key,
`dealer_application_response_window` (seeded default: `"2-3 business
days"`), read the same way `newsletter_enabled` already is
(`SettingsRepository::get()`/`bool()` with a matching hard-coded fallback).

**`reviewed_at` is stamped by the repository, not the service** — the same
split `ReviewRepository::approve()`/`reject()` already use for
`moderated_at`: `DealerApplicationRepository::updateWithReviewTimestamp()`
is called instead of the generic `update()` whenever the service decides
`status` has moved away from `SUBMITTED`, keeping `now()` a repository
concern and "when to stamp it" a service (business-logic) decision.

**`company_name` is required** here, unlike enquiries — the schema says so
(`NOT NULL`, no equivalent to `product_enquiries.company_name`'s
nullability), consistent with a dealer application being inherently
business-to-business. **`years_of_experience` stays `null` when omitted**
rather than defaulting to `0` — "not provided" and "zero years" are
different facts, and the column is nullable precisely because the form
doesn't require it.

**An unrelated observation, not acted on:** the actual wire shape of every
list-returning endpoint in this codebase — confirmed live for both
`/admin/enquiries` (pre-existing) and `/public/gallery/categories`
(pre-existing) — nests one level deeper than `docs/openapi.yaml` documents
it (`{"data": {"data": [...], "meta": {...}}}`, not `{"data": [...], "meta":
{...}}`), because `Kernel::handle()` wraps whatever a controller returns
directly as the envelope's `data` with no special-casing of a `data`/`meta`
key. `docs/openapi.yaml`'s `check` script only diffs routes against paths,
not response bodies, so this drift is invisible to tooling. This ticket's
own `LocationController` deliberately returns bare lists (matching
`CategoryController::index()`'s already-correct style) rather than
`['data' => ...]`, so `/public/locations/districts` and its upazilas route
match their own documented shape exactly; the admin list/export endpoints
here intentionally match the *existing* (nested) convention every other
paginated admin endpoint already uses, for consistency with siblings rather
than with the doc. Fixing the doc (or the wrapping) project-wide is out of
this ticket's scope and is left as a finding for whichever ticket touches
the response pipeline next.

Verified live against the running dev server: the full district list (64,
alphabetical), a district's upazilas, a 404 for a made-up district id,
anonymous application submission and its modal payload
(`applicationId`/`submittedAt`/`expectedResponseWindow`), field-level
validation (bad email, mismatched district/upazila), the admin
list/show/update/export cycle, and `reviewed_at` being stamped on the first
status change. 16 new tests across `tests/Feature/LocationTest.php` and
`tests/Feature/DealerApplicationTest.php` (submission, the
rollback-leaves-no-gap proof, the `ENQ`/`DA` independence proof, validation,
admin CRUD, CSV).

---

## Contact messages and newsletter (Phase 2, RTPP-31)

The last two of the doc's four rate-limited public forms (§14.2) — the
contact form and newsletter subscribe/unsubscribe — plus their admin inbox
and subscriber list.

**Ticket text had one stale line, fixed before implementation**: "Both
brand-scoped" — a leftover from the two-brand era. Neither `contact_messages`
nor `newsletter_subscribers` has a `brand_id` column in the current
single-site schema.

**Contact is the simplest of the three lead-capture forms**: no reference
number, no assignee, and — unlike every other submissions table in this
schema — no `updated_at` column, only `created_at` and `replied_at`
(`database/migrations/007_submissions.sql`). `ContactMessageRepository::update()`
never assigns a column that doesn't exist. `replied_at` is (re-)stamped by
the repository, not the service, whenever `status` is set to `REPLIED` — the
same split `DealerApplicationRepository::updateWithReviewTimestamp()`
already established for `reviewed_at` and `ReviewRepository::approve()`/
`reject()` established first for `moderated_at`: `now()` stays a repository
concern, "when to stamp it" a service decision.

**Newsletter subscribe is idempotent by design, not by accident.** The DoD's
own wording — "re-subscribing an existing address does not create a
duplicate or error the visitor" — matters because `email` is a unique
column (`uq_newsletter_email`): a naive second `INSERT` would be a
constraint violation, not silently harmless. `NewsletterService::subscribe()`
branches on the row's current state instead: an address already actively
subscribed is a silent no-op (same row, same token, `subscribed_at`
untouched); one that had previously unsubscribed is reactivated in place
(`is_subscribed` back to `1`, `unsubscribed_at` cleared, `subscribed_at`
refreshed) — keeping its *existing* `unsubscribe_token` rather than issuing
a new one, since the token identifies the row, not a subscription period.
Verified at the row level in `NewsletterTest.php`, not just that the call
didn't throw: one row, unchanged token, both before and after a resubscribe.

**"Not guessable" is the token's own entropy**, not a signed-in session:
`bin2hex(random_bytes(32))`, 64 hex characters, matching the schema's
`unsubscribe_token CHAR(64)` exactly. `GET /public/newsletter/unsubscribe/:token`
is deliberately unauthenticated (that is the whole point of a one-click
email link) and deliberately not rate-limited — `ThrottleForm` itself
already skips `GET` requests (only a submission counts against the
five-per-hour budget), so wrapping a `GET` route in it would be a no-op.
Unsubscribing is idempotent too, the same as every delete/state-change
convention in this codebase: an already-unsubscribed token still succeeds.
`unsubscribe_token` is deliberately never exposed in the admin subscriber
view — it is that link's only credential.

**Third occurrence, extracted:** `requiredEmail()` — required text plus
`filter_var(..., FILTER_VALIDATE_EMAIL)` — had been copied once already
(`EnquiryService`, RTPP-29, then `DealerApplicationService`, RTPP-30) before
this ticket needed the identical two lines a third time. Following the
exact reasoning `ValidatesInput`'s own class doc already states for its
other methods ("copying it a second time is the moment this stopped being
coincidence"), it is now one method on the shared `ValidatesInput` trait;
both existing services were refactored onto it, removing two duplicate
private copies. All pre-existing tests for both tickets still pass
unchanged after the refactor.

**`DELETE /admin/subscribers/:id` is Super Admin only** (doc §9.10), even
though `Capability::NEWSLETTER`'s WRITE cell is shared with Sales — the
identical situation, and the identical fix, RTPP-27 used for review
deletion: `RequireSuperAdmin` stacked after `RequireRole::write()` narrows
one specific route past what the capability matrix alone allows, rather
than changing the matrix's shape.

**Scope decision, following the precedent set for RTPP-29/RTPP-30:** neither
"the contact submission notifies the contact list" nor "newsletter signup
sends a welcome email to the subscriber" (doc §14.4) is built here, for the
identical reason — no mail infrastructure exists yet (`RTPP-33`, still
`To Do`). `contact_notify_emails` and `newsletter_notify_emails` (both
already seeded, `SettingsSeeder`) are the recipient/list values RTPP-33
will read; the welcome email itself has no settings key because it is sent
to the subscriber, not to an admin-configured list.

Verified live against the running dev server: contact submission and its
admin inbox, marking a message replied (and `replied_at` being stamped),
newsletter subscribe, a resubscribe of the same still-active address
staying a single row, unsubscribe by token with no auth, a 404 on a
made-up token, the admin subscriber list, CSV export, and the Super-Admin-
gated delete (idempotent on a second call). Test data cleaned from the dev
database afterward. 20 new tests across `tests/Feature/ContactMessageTest.php`
and `tests/Feature/NewsletterTest.php`.

---

## Settings, navigation and downloads (Phase 2, RTPP-32)

Admin key/value settings, menu links, social links, and the downloads
module — everything doc §9.8/§9.9 lists alongside the site profile that
already shipped from RTPP-14.

**Ticket text had two stale items, fixed before implementation.** First,
"setting keys unique per brand" and "Brand settings" — two-brand-era
language; `settings` has no `brand_id` column, and the bullet's actual
content (theme colours, logo assets, contact block, map coordinates, SEO
defaults) already ships from RTPP-14's `/admin/site-profile` — this
ticket's real remaining scope was always narrower than the bullet made it
look. Second, "Blocked by the §19 open item on whether brochure downloads
require an email address first" — that item was resolved 2026-09-13
("Open — no email required before the file is served"), so the ticket was
not actually blocked; `downloads.requires_email` stays in the schema for a
future ticket but is not enforced here.

**Settings is a bulk key/value endpoint, not per-key CRUD**, because the
document already describes it that way ("GET/PUT /admin/settings — key/value
settings") and the set of keys is small and bounded. `PUT` is an *update*
only — `settings.key` is an allowlist populated by `SettingsSeeder`, never
something client input may create, so a typo'd key comes back as a `422`
naming it rather than silently becoming a dead setting nothing reads. This
is what makes the ticket's "notification recipient lists actually drive who
receives each email type" true starting now: the four `*_notify_emails`
keys are admin-editable from this ticket onward, and `RTPP-33`'s mailer
(still `To Do`) is what will actually read them.

**Menu links keep one delete-time subtlety enquiries/applications/messages
don't have**: `parent_id` is a self-referential foreign key with no
`ON DELETE` clause, so deleting a link that still has children would
otherwise surface as a raw `PDOException` from the database rather than a
clean API error. `MenuLinkService::delete()` checks for children first and
returns a `409 CONFLICT` naming the problem — the same spirit as
`MediaRepository`'s reference check before a delete, applied to the one
self-referencing table in this codebase's admin CRUD scope. Verified live:
deleting a link with a child fails with `409`; deleting the child first,
then the parent, succeeds.

**Social links' `platform` is deliberately unconstrained** beyond the
schema's own `VARCHAR(64)` — the column comment lists common examples
(facebook, instagram, linkedin, youtube, whatsapp) but is not an `ENUM`,
so this service doesn't invent a stricter rule the schema itself doesn't
enforce. Verified with a non-listed platform ('tiktok') in
`SocialLinkTest.php`.

**Downloads resolves by a stable `key`, not by id** — `dealer_brochure`,
not a ULID the front-end would have to look up first — because the
front-end's download button is built against a known key. `key` is
admin-chosen on create, not derived from `title`, using the identical
two-layer uniqueness pattern `CategoryService` uses for slugs
(`keyExists()` pre-check, then `uq_downloads_key` catching the race a
pre-check cannot, translated to the same `409`). `GET /public/downloads/{key}`
never gates on `requires_email` — see the resolved §19 item above — and
increments `download_count` with the same simple read-then-increment idiom
`NewsService::show()` already uses for `view_count`: a lost increment under
a genuine race is a cosmetic under-count on a page-view-style counter, not
a correctness issue, and doesn't justify locking machinery nothing else in
this codebase needed either.

**One observation, not acted on:** the ten-plus services already in this
codebase (`Certification`, `FeatureItem`, `GalleryImage`, `Banner`,
`PageBlock`, `Testimonial`, `GalleryCategory`, `Product`, `ProcessStep`,
`Review`, `StatCounter`, now `MenuLink` and `SocialLink` too) each carry
their own private, near-identical `validateIdList()` copy for reorder
input. Unlike `requiredEmail()` (RTPP-31, two prior copies, a two-line
trait-ready method), extracting this one would mean touching a dozen
already-Done, already-tested files across several phases for a single
ticket that didn't ask for it — a much larger blast radius than this
ticket's own scope justifies. Left as a candidate for a dedicated cleanup
ticket, following this same `README`'s established practice of naming a
finding rather than silently acting on or silently ignoring it.

Verified live against the running dev server: settings list/update and the
unknown-key rejection, a parent/child menu link and the `409` on deleting
the parent first, social link create/reorder, a download's full
create → resolve (twice) → admin-view-shows-count-2 cycle, and `requires_email:
true` still resolving with no email gate. Test data cleaned from the dev
database afterward. 30 new tests across `tests/Feature/SettingsTest.php`,
`tests/Feature/MenuLinkTest.php`, `tests/Feature/SocialLinkTest.php` and
`tests/Feature/DownloadTest.php`.

---

## Email notifications (Phase 2, RTPP-33)

The §14.4 notification matrix, wired into every deferred email hook the
last five tickets left standing, plus the weekly `LeadDigest` job.

**Ticket text fixed before implementation.** "Recipients resolved per
brand" was two-brand-era language — `settings` has no `brand_id` — and the
"Blocked by §19 items 4 and 7" clause was stale: item 4 (recipient lists)
was resolved by RTPP-32's own admin CRUD, and item 7 (SMTP provider) was
resolved the same day (the client's Gmail account). What's genuinely still
open is narrower than the ticket's own blocker made it sound: only the real
Google App Password itself, a client-supplied credential not yet provided
— tracked the same way as the other RTPP-12/21/34 credential gaps.

**`Mailer::send()` never throws**, by design — the whole point of the
ticket's own title, "inline send with logged retry". An unconfigured
`MAIL_HOST` (this repo's actual state right now: `.env` has no real SMTP
credentials, per the still-open App Password dependency above) is treated
as a no-op, not an error, logged at `info` rather than `error` — every
caller in this codebase already assumes "commit first, mail second, mail
failure never fails the request," and the test suite proves that
assumption holds for real: all 730 tests still pass with mail completely
unconfigured, because every wired-in call site behaves exactly as it did
before this ticket when the mailer can't send.

**The DoD's "killing the SMTP connection still returns 200... measure the
added latency" was verified live, with a real number, not reasoned about.**
Pointed `MAIL_HOST` at a non-routable address (`10.255.255.1`) for one
throwaway dev-server process and submitted a real contact form:

```
HTTP 200 in 5.058534s
{"success":true,"data":{"received":true}}
```

The application's own log recorded the same figure from the inside:
`"elapsed_ms":5016`. **This is the number the ticket's escalation-path
bullet asks for**: a completely dead SMTP server adds a full 5 seconds
(the configured `mail.timeout_seconds`) to a public form submission before
this repo's normal, unconfigured-mail dev environment showed anything
faster. That is a real, user-visible delay on a public form, and worth the
client's attention once the App Password lands and this can be measured
against a real (as opposed to a completely dead) SMTP server too — the
number that matters for the `mail_queue` decision is *successful* send
latency, which cannot be measured without a live account. Recorded on this
ticket rather than acted on: the ticket's own words are "decide by
measurement, not in advance," and there is only half the measurement
available until the credential exists.

**Recipients resolve from `settings`, falling back to
`site_profile.email_primary`** exactly as doc §19 item 4 records —
`NotificationRecipients::forSetting()` — for every list-based event
(enquiry, dealer application, contact). Verified live: with every
`*_notify_emails` key still at its seeded empty default, an enquiry
submission's notification correctly fell back to and logged
`info@rajdhanifood.com`, the seeded site profile's primary email — not a
silently-dropped notification.

**"Review submitted"'s recipients are live `admin_users` holding the
Editor role**, not a `settings` key — `AdminUserRepository::activeEmailsForRole()`
— because an editor list should always be whoever currently holds the
role, not a separately maintained address list that can drift from it. New
dedicated test, `AdminUserRepositoryTest.php`.

**Two of the eight events don't fit the "resolve a list, send a copy"
shape**, and neither invents anything not already true: newsletter's
welcome email goes to the subscriber themselves, sent only on the two
`subscribe()` branches that actually change something (a new row, or
reactivating one) — never on the silent-no-op branch, since re-welcoming
someone already subscribed would be exactly the noise the ticket's
resubscribe DoD is about avoiding, applied to the inbox as well as the
database. "Admin invited" has **no live trigger at all**: admin user
creation (`AdminUserService`, doc §9.11's invite flow) does not exist
anywhere in this codebase yet — `AdminUserRepository`'s own pre-existing
class doc is explicit that it has no `create()` — and is not in Phase 2's
19-work-package list. `AdminInviteMail` is built and ready; nothing calls
it yet, documented transparently in its own class doc rather than left as
a silent gap.

**A new config value, `ADMIN_URL`**, was needed for two "Action link"
emails (invite, password reset) to point somewhere real: this backend has
no confirmed admin-dashboard base URL otherwise (the front-end is a
separate developer's scope, doc §19 deviation 7, and `CORS_ORIGINS` lists
every allowed origin without saying which one is the admin dashboard). Not
hardcoded, following doc §19 item 1's own resolution for the admin origin
("read at runtime, not hardcoded") applied to an outbound link instead of
an inbound CORS check. Defaults to this repo's local dev admin port; a
`GET /admin/reviews`-style moderation-queue link in the "review submitted"
email was deliberately **not** built the same way — a guessed admin route
path is worse than an email that doesn't link one.

**LeadDigest** (`app/Jobs/LeadDigest.php`, `bin/lead-digest.php`) is the
one job this ticket scopes — `TokenCleanup`, `MediaCleanup` and
`SitemapBuild` are doc §13's other three `app/Jobs/` entries and are not
named in this ticket. A dedicated `LeadDigestRepository` counts new
enquiries, applications, messages and subscribers over the last 7 days
directly, rather than reusing four services' own filtered-listing shapes
for a report none of them were built to produce; sent to
`enquiry_notify_emails` — the same setting §14.4's own enquiry row already
calls "Sales list", not a new key that would need separately maintaining.
Verified live via `php bin/lead-digest.php` against the real dev database.

**PHPMailer added** (`composer require phpmailer/phpmailer`) — the ticket
names it explicitly, and hand-rolling Gmail's STARTTLS/AUTH handshake
correctly and securely is not a reasonable thing to reinvent for one
mailer class.

Verified live against the running dev server, beyond the SMTP-down
measurement above: an enquiry, a dealer application, a newsletter
subscribe, and an admin password-reset request all correctly produced a
"Mail skipped — SMTP not configured" log entry naming the right recipients
and subject (the enquiry and dealer-application ones correctly falling
back to the site profile's email); `bin/lead-digest.php` ran against the
real database and exited 1 with a clear message, matching its own
documented "not necessarily a failure" contract. Test data cleaned from
the dev database afterward. 39 new tests across
`tests/Unit/MailerTest.php`, `tests/Unit/MailableTest.php`,
`tests/Feature/NotificationRecipientsTest.php`,
`tests/Feature/AdminUserRepositoryTest.php` and
`tests/Feature/LeadDigestTest.php` — none for the SMTP-timeout scenario
itself, deliberately: `config()` caches each file's values in a `static`
array for the life of the PHP process, so a test that overrides `MAIL_HOST`
after any earlier test has already read `config('mail.*')` would leak into
every later test in the same run. That scenario is a live, one-process
verification instead, not a unit test.

**A pre-existing observation, unrelated to this ticket's own scope, found
while wiring `EnquiryService`/`DealerApplicationService`:** doc §8.2's
"reference number generation" prose already reflects the RTPP-17
gaps-are-acceptable simplification (a single atomic `INSERT … ON DUPLICATE
KEY UPDATE`), but the actual, already-shipped RTPP-29/30 implementation
still uses the stronger, more complex locked-counter-row mechanism with
deadlock retry — strictly more than the document now asks for, not
broken, just undocumented drift between what shipped first and what the
document was later simplified to. Not touched here: reopening two Done,
extensively load-tested tickets to match a since-relaxed requirement is
out of this ticket's scope.

---

## Public form protection (Phase 2, RTPP-34)

reCAPTCHA v3 plus a honeypot field on every public form the ticket names —
enquiry, dealer application, contact, newsletter, and (new this ticket)
review submission, which wasn't rate-limited at all before this.

**One shared middleware, `VerifyHuman`**, does both checks — a filled
honeypot field (`website`, a name no real field on any of the five forms
uses) is rejected before `RecaptchaVerifier` even runs. Both rejections,
and every reCAPTCHA rejection reason (no token, low score, action
mismatch), produce the identical generic error: the DoD requires a clear
message that never reveals the threshold or which check actually failed.

**`RecaptchaVerifier` treats an unconfigured secret key as a logged skip**,
the identical resilience choice `Mailer` makes for `MAIL_HOST` (RTPP-33) —
reCAPTCHA keys are a client-supplied credential (doc §19) not yet in this
repo's `.env`. A transport failure (Google unreachable) **fails open**,
logged as a warning: a bot getting through during a Google outage costs
less than blocking every real customer, and honeypot plus the per-form
rate limit are still active independently either way. A *configured* key
with a genuinely bad verdict fails closed — proved live by temporarily
setting `RECAPTCHA_SECRET_KEY` for one throwaway dev-server process: a
token-less submission correctly came back `403`, and a submission with a
real (fake-secret) token round-tripped to Google's actual `siteverify` and
was correctly rejected on Google's own `success: false`.

**A real bug, found by that live round-trip, not by a test double**: PHP
8.5 deprecates `curl_close()` ("has no effect since PHP 8.0"), and this
app's own error handler (`Kernel::boot()`) turns every deprecation into a
thrown exception — so `HttpClient::post()`/`get()` threw on every call
under PHP 8.5, on *any* caller, not just this ticket's. It went unnoticed
until now because nothing before this ticket had ever exercised
`HttpClient`'s real network path outside a faked test double — every
existing caller (`GoogleJwkSource`) is only ever tested against a fake.
Fixed by deleting the now-inert `curl_close()` calls; see `HttpClient`'s
own class doc for the full account. Worth flagging: `RecaptchaVerifier`'s
own deliberate "fail open on transport error" design initially *masked*
this as a harmless-looking warning log rather than a loud failure —
exactly the kind of real, non-hypothetical case that resilience design is
for, but also a reminder that a broad `catch (Throwable)` will swallow a
genuine bug just as readily as an expected one.

**Score threshold is admin-configurable** (`settings.recaptcha_score_threshold`,
seeded `0.5`) rather than a code constant — the document names only "a
score threshold" with no confirmed number, the same "not hard-coded"
treatment every other undecided business value in this table gets.

**Testability without a database or a real Google call**: `RecaptchaClient`
is an interface (mirroring `JwkSource`'s own reasoning) with a
`FakeRecaptchaClient` test double scripting every verdict shape —
including the transport failure a real outage would produce, which no
real HTTP call could produce on demand. `RecaptchaVerifier` takes its
secret key and score threshold as constructor overrides for the identical
reason `EnquiryService` etc. take an optional `?PDO $connection`:
`config()` caches each file's values for the life of the PHP process, so a
test needing "configured" behaviour injects a value directly rather than
mutating global state that would leak into every other test in the run.

Verified live against the running dev server: a normal submission on an
unconfigured install still succeeds, a filled honeypot is rejected on the
enquiry and newsletter forms, and (with a temporarily configured secret
key) a missing token is rejected and a present-but-invalid token correctly
round-trips to Google and is rejected on the real response. Test data
cleaned from the dev database afterward. 13 new tests: 9 in
`tests/Unit/RecaptchaVerifierTest.php`, 4 new cases added to
`tests/Unit/SecurityMiddlewareTest.php`.

---

## Layout

Only `public/` is web-exposed. Everything else sits above it and is unreachable
over HTTP, enforced by pointing the domain's document root at `public/`. Where a
host will not allow that, the `.htaccess` deny at the project root is the weaker
fallback and must be security-reviewed (doc §16.2 item 4).

```
app/
  Controllers/   HTTP only: parse, delegate, respond
  Services/      business rules; the only caller of repositories
  Repositories/  the only layer that issues SQL
  Middleware/    Cors, SecurityHeaders, auth, roles, validation, rate limit, audit
  Helpers/       ApiResponse, ApiError, ErrorCode, Pagination, SlugHelper, UlidHelper
  Support/       Env, Logger, Database
  Http/          Request, Router
  Kernel.php     boot, dispatch, and the only place errors become responses
```

**Layering is a rule, not a style.** A controller never touches a repository; a
service never touches `$_REQUEST`. A service that reaches for a superglobal
cannot be unit-tested and cannot be reused from a cron job.

---

## Conventions

- Every response is the §9.1 envelope: `{ success, data, meta }` or
  `{ success, error: { code, message, details } }`. `ApiResponse` is the only
  thing that writes a body.
- Error codes are a closed set (`ErrorCode`). The front-end switches on
  `error.code`, so adding one is a contract change and belongs in the document
  first.
- Primary keys are ULIDs in `CHAR(26)`, generated in PHP. The one exception is
  `site_profile`, a `TINYINT` singleton pinned by `CHECK (id = 1)`.
- All SQL goes through PDO prepared statements, inside a repository. No string
  interpolation into a query, anywhere.
- Anything thrown that is not an `ApiError` is a bug: it is logged with its trace
  and answered with a generic `INTERNAL_ERROR`. With `APP_DEBUG=false` no
  message, path or query ever reaches a client.

---

## Not yet done in Phase 1

RTPP-90 (cPanel prerequisites — **unconfirmed**) — the last Phase 1 item, and
it needs the hosting account rather than code.

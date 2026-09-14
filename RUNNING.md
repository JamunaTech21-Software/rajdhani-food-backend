# Running the API locally

## Every session

```bash
cd "backend/api"
export PATH="/opt/homebrew/opt/php@8.3/bin:$PATH"   # PHP 8.3 is keg-only, not on PATH
./bin/mysql8.sh start                                # MySQL 8 on port 3307
php -S 127.0.0.1:8000 -t public public/index.php     # leave this running
```

Base URL: **`http://127.0.0.1:8000/api/v1`**

Check it is up:

```bash
curl http://127.0.0.1:8000/api/v1/health
curl http://127.0.0.1:8000/api/v1/health/db     # 200 = database reachable, 503 = not
```

## First time only — claim the Super Admin

The seeded admin has **no password** by design (doc §7.2). It holds an invite
token, and login is refused until that invite is accepted.

```bash
# 1. get the token
TOKEN=$(./bin/mysql8.sh cli rajdhani_dev -N -e \
  "SELECT invite_token FROM admin_users WHERE email='admin@rajdhanifood.com';" | tr -d '[:space:]')

# 2. check it is valid (returns name, email, role)
curl -s "http://127.0.0.1:8000/api/v1/auth/admin/invite/$TOKEN"

# 3. set a password — min 10 chars, upper + lower + digit + symbol
curl -s -X POST "http://127.0.0.1:8000/api/v1/auth/admin/invite/$TOKEN/accept" \
  -H 'Content-Type: application/json' \
  -d '{"password":"Rajdhani#Tea2026"}'
```

`php bin/seed.php` also prints the token every time it runs.

## Logging in

```bash
ACCESS=$(curl -s -c /tmp/rajdhani-cookies.txt \
  -X POST http://127.0.0.1:8000/api/v1/auth/admin/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@rajdhanifood.com","password":"Rajdhani#Tea2026"}' \
  | python3 -c "import json,sys; print(json.load(sys.stdin)['data']['tokens']['access_token'])")

curl -s http://127.0.0.1:8000/api/v1/auth/admin/me -H "Authorization: Bearer $ACCESS"
```

Two things to know:

- The **access token** goes in `Authorization: Bearer …` and lasts 20 minutes.
- The **refresh token** is an `HttpOnly` cookie — it is never in the response
  body. Use `-c` to save it and `-b` to send it, or refresh will fail.

```bash
curl -s -b /tmp/rajdhani-cookies.txt -c /tmp/rajdhani-cookies.txt \
  -X POST http://127.0.0.1:8000/api/v1/auth/admin/refresh
```

Refresh tokens are **single-use**. Presenting one twice is treated as a stolen
token and revokes the whole session family — that is deliberate, not a bug.

## The 21 endpoints that exist today

### Open

| | |
|---|---|
| `GET /health` | liveness |
| `GET /health/db` | database reachability |
| `GET /public/layout` | site profile, theme, menus, social links — no auth, no headers |

### Admin auth — `/auth/admin/*`

| | |
|---|---|
| `POST /login` | email + password → access token + refresh cookie |
| `POST /refresh` | rotate (needs the cookie) |
| `POST /logout` | revoke |
| `GET /me` | profile, role, and the §7.3 permission matrix |
| `PATCH /me` | name, phone, avatar only |
| `POST /change-password` | needs the current one; ends every session |
| `POST /forgot-password` | always says the same thing |
| `POST /reset-password` | consume the token |
| `GET /invite/:token` | validate an invite |
| `POST /invite/:token/accept` | set password, sign in |

### Customer auth — `/auth/customer/*`

| | |
|---|---|
| `POST /google` | `{"credential": "<Google ID token>"}` |
| `POST /refresh`, `POST /logout` | as above |
| `GET`/`PATCH`/`DELETE /me` | profile; PATCH allows phone, city, company only |

**These need a real Google ID token**, which only a browser running Google
Identity Services can mint. You cannot exercise them with curl alone — the API
verifies the signature against Google's public keys and rejects anything else.
They are covered by 38 automated tests that sign real tokens with a generated
key pair.

### Admin — `/admin/*`

| | |
|---|---|
| `GET /site-profile` | Super Admin only |
| `PATCH /site-profile` | Super Admin only |

Try the role gate: log in as the Super Admin, `PATCH` a colour, then watch
`GET /public/layout` return it immediately.

```bash
curl -s -X PATCH http://127.0.0.1:8000/api/v1/admin/site-profile \
  -H "Authorization: Bearer $ACCESS" -H 'Content-Type: application/json' \
  -d '{"primary_color":"#8E24AA"}'

curl -s http://127.0.0.1:8000/api/v1/public/layout    # theme.primary is now #8E24AA
```

## Everything else is 404 — on purpose

Products, categories, banners, gallery, news, enquiries, dealer applications,
reviews and wishlist are Phase 2 onward. The tables are seeded and the data is
there; the endpoints are not written yet.

## Resetting

```bash
./bin/mysql8.sh cli -e "DROP DATABASE IF EXISTS rajdhani_dev;
  CREATE DATABASE rajdhani_dev CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php bin/migrate.php up
php bin/seed.php          # prints a fresh invite token
```

## Troubleshooting

| Symptom | Cause |
|---|---|
| `Could not connect` on any endpoint | `./bin/mysql8.sh start` |
| `php: command not found` | the `export PATH=` line is missing |
| Login returns 401 with the right password | the invite was never accepted |
| Login returns 429 | 5 failed attempts locks the account for 30 minutes — `DELETE FROM login_attempts;` |
| Refresh returns 401 | the cookie was not sent (`-b`), or the token was already used |

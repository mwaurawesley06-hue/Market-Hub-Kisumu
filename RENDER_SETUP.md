# Deploying MPIDS to Render

Render only offers managed **Postgres** and **Redis**, so the app's MySQL
database runs on a free external MySQL-compatible service. The recommended
provider is **TiDB Cloud Starter** (MySQL protocol compatible, free forever
without a credit card, 5 GiB storage + 50M request units/month).

## 1. Create the database (TiDB Cloud)

1. Sign up at <https://tidbcloud.com> (no credit card needed).
2. **Create Instance → Starter → MySQL Compatible**, pick a region near
   Nairobi (e.g. `ap-southeast-1` or `us-west-2`), name it `mpids`.
3. Keep **Spending Limit = 0** so it stays free.
4. When the instance is ready, open its **Connect** dialog and note the
   **host**, **port** (4000), **user** and **password**.
   - Starter instances prefix the user: `<userPrefix>.root`.
   - The root password is **set at creation only** — there is no self-service
     reset, so store it somewhere safe immediately.

## 2. Load the schema + data

Skip if you are working from the live deployment (already imported).

The repo ships a ready-made import file with the full schema and all current
Kisumu data: `database/mpids-tidb-import.sql` (no triggers — TiDB does not
support them; generated with `--skip-triggers`).

Easiest path — use TiDB's web SQL console (**Chat2Query / SQL Editor**):

1. Open the SQL editor for the instance and run the contents of
   `mpids-tidb-import.sql` (paste in chunks if needed).

Or with the MySQL CLI from any machine:

```bash
mysql --host gateway01.<region>.tidbcloud.com --port 4000 \
  -u root -p < database/mpids-tidb-import.sql
```

## 3. Create the Render service

1. Render Dashboard → **New → Web Service** → connect the GitHub repo
   `mwaurawesley06-hue/Market-Hub-Kisumu`.
2. **Branch:** `backend` · **Runtime:** Docker · Plan: Free.
   Render's API does not offer a PHP runtime, so the repo ships a
   `Dockerfile` (`php:8.2-cli` + `pdo_mysql`); Render builds it and runs
   `php -S 0.0.0.0:${PORT:-10000} router.php`.
3. Environment variables (set on the service, or let `render.yaml` seed them):

   | Key | Value |
   |-----|-------|
   | `DATABASE_URL` | `mysql://<prefix>.root:<password-url-encoded>@<host>:4000/mpids` |
   | `MPIDS_DB_SSL` | `1` |
   | `MPIDS_DB_SSL_VERIFY` | `0` (see TLS note) |
   | `CORS_ORIGIN` | `https://mwaurawesley06-hue.github.io` |

   URL-encode special characters in the password (`@` → `%40`, `!` → `%21`).
   Gotcha: Render's `PUT .../env-vars` API **replaces the whole set** — always
   send every variable, not just the one you are changing.

4. Deploy. When the first deploy finishes, `https://<your-service>.onrender.com/api/health/`
   must return `"ok": true` and `"database": true`.

### TLS note

TiDB Serverless requires encrypted connections; a plain socket is refused
with *"Connections using insecure transport are prohibited"*. Setting
`MPIDS_DB_SSL=1` makes `database.php` pass a CA path to pdo_mysql — TiDB
uses **Let's Encrypt**, so the Debian CA bundle inside the Docker image
(`/etc/ssl/certs/ca-certificates.crt`) verifies it. No certificate download
is needed. Keep `MPIDS_DB_SSL_VERIFY=0` (encrypt, skip hostname pinning) so
a routine Let's Encrypt rotation on TiDB's side cannot break the app; use
`1` only if you accept occasional reconnect churn.

## 4. Seed the admin account

Already handled: `mpids-tidb-import.sql` ships an active admin
(`admin@mpids.co.ke`). To add another account, Render → service → **Shell**:

```bash
php api/scripts/create-admin.php you@example.com 'Strong-Password-Here' 'Your Name'
```

Then open `https://<your-service>.onrender.com/login` and sign in.

## 5. Point the public site at the API

Done on `main`: `js/config.js` sets
`apiBase: 'https://mpids-api.onrender.com/api'`. Live prices come from the
Render API only — if it is unreachable the pages show an error state (there
is no offline demo dataset), and SMS registrations write to the TiDB
database.

## Troubleshooting

- `/api/health/` returns `"database": false` — `DATABASE_URL` host/port/user
  wrong, or the TiDB instance was paused; check the `db_error` field in the
  health response.
- `Connection refused` to `127.0.0.1` with user `root` — the app never saw
  `DATABASE_URL` (env var missing or wiped by a partial env-vars PUT) and
  fell back to local defaults.
- `Connections using insecure transport are prohibited` — `MPIDS_DB_SSL` is
  off or the CA bundle path does not exist on the image.
- CORS errors in the browser — `CORS_ORIGIN` must exactly match the Pages URL.
- "Unknown database" — run the import file again; the database name in
  `DATABASE_URL` (last path segment) must exist.

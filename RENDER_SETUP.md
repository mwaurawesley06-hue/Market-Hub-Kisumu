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
4. When the instance is ready, open its **Connect** dialog:
   - Note the **host**, **port** (4000), **user** (`root` by default) and the
     generated **password**.
   - Download the **CA certificate** file offered in that dialog (needed below).

## 2. Load the schema + data

The repo ships a ready-made import file with the full schema and all current
Kisumu data: `database/mpids-tidb-import.sql`.

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
2. **Branch:** `backend` · **Runtime:** PHP · Plan: Free.
   Render detects PHP via `composer.json` and uses the start command from
   `render.yaml` (`php -S 0.0.0.0:$PORT router.php`).
3. Environment variables (set on the service, or let `render.yaml` seed them):

   | Key | Value |
   |-----|-------|
   | `DATABASE_URL` | `mysql://root:<password>@gateway01.<region>.tidbcloud.com:4000/mpids` |
   | `MPIDS_DB_SSL` | `1` |
   | `MPIDS_DB_SSL_VERIFY` | `0` (or `1` + see TLS note) |
   | `CORS_ORIGIN` | `https://mwaurawesley06-hue.github.io` |

4. Deploy. When the first deploy finishes, `https://<your-service>.onrender.com/api/health/`
   must return `"ok": true` and `"database": true`.

### TLS note

TiDB Serverless requires encrypted connections. `MPIDS_DB_SSL=1` enables TLS;
`MPIDS_DB_SSL_VERIFY=0` encrypts without verifying the server certificate
(fine for this workload). For full verification, commit the downloaded CA
file to the repo (e.g. `database/tidb-server-root.crt`) and instead set:

```
MPIDS_DB_SSL_VERIFY=1
MPIDS_DB_SSL_CA=/opt/render/project/src/database/tidb-server-root.crt
```

## 4. Seed the admin account

The schema ships with no admin user. Render → service → **Shell**:

```bash
php api/scripts/create-admin.php you@example.com 'Strong-Password-Here' 'Your Name'
```

Then open `https://<your-service>.onrender.com/login` and sign in.

## 5. Point the public site at the API

On `main` (GitHub Pages site), edit `js/config.js`:

```js
window.MPIDS_CONFIG = {
    apiBase: 'https://<your-service>.onrender.com/api'
};
```

Commit to `main`. From then on, live prices come from the Render API (falling
back to `data/prices.json` if the API is unreachable) and SMS registrations
write to the TiDB database.

## Troubleshooting

- `/api/health/` returns `"database": false` — `DATABASE_URL` host/port/user
  wrong, or the TiDB instance was paused; check the `db_error` field in the
  health response.
- CORS errors in the browser — `CORS_ORIGIN` must exactly match the Pages URL.
- "Unknown database" — run the import file again; the database name in
  `DATABASE_URL` (last path segment) must exist.

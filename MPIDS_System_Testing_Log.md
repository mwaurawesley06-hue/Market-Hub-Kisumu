# MPIDS SYSTEM TESTING LOG

**Functional Testing, Defect Corrections and Retest Record**

**Purpose:** This log documents the testing activities executed for the MPIDS system, including the test action, observed result, corrective action (where applicable), and final status based on the actual retest outcome.

**Retest execution record**

| Field | Value |
|---|---|
| Retest date/time | 2026-10-14, 20:54–21:15 (EAT, server clock) |
| Tester | Automated retest executed by the coding agent (Buffy / Codebuff) under user instruction, against the running local system |
| System under test | MPIDS — Market Price Information Distribution System (this workspace: `C:\xampp\htdocs\MPDIS`) |
| Application version | Working tree as of 2026-10-14 (PHP 8.2.12, Apache/2.4.58, MySQL `mpids` DB) |
| Test environment | `http://127.0.0.1/MPDIS/` — Apache/PHP served locally; Chromium browser (375×812 and 376×812 viewports) + authenticated HTTP (curl) with session cookie and CSRF token. TC-01 valid-credential path completed 2026-10-14 21:42 after the admin password was (re)seeded via `database/seed.php` |
| SMS gateway | Simulation mode (`sms_gateway_simulated = true`) — no real SMS left the machine; sends recorded to `api/tmp/logs/sms-sim.log` |

**Scope note (feature mapping):** The originally supplied log described a pharmacy-style inventory system (pharmacist roles, stock-in/out, stock audit, password-reset tokens). That system does not exist in this workspace. Per user instruction, the retests below were executed against this workspace's actual MPIDS (market price) system, mapping each original test case to its real equivalent. `Passed (reported)` statuses from the original log are **not** carried forward — every status below reflects a retest executed on 2026-10-14, or is explicitly recorded as Pending / Not implemented.

---

## 1. Test Summary

| Test ID | Feature | Initial Finding (original log) | Retest Action | Retest Result (2026-10-14) | Final Status |
|---|---|---|---|---|---|
| TC-01 | User Login | Invalid credentials did not show an error message. | Submitted invalid credentials, empty password, and valid credentials to `POST /api/auth/login`; verified session, authenticated page load, and logout | Invalid → `401 {"detail":"Invalid email or password."}`; empty → `422` field error; valid → `200` with user + CSRF; session accepted by `/api/auth/me` and `/settings`; logout → `200`, then `401` | **Passed after correction (retest verified — all three credential paths)** |
| TC-02 | Access Control | Unauthorized pages redirected to login. | Unauthenticated requests to pages, APIs and writes; authenticated probe session check | Pages → `302 → /MPDIS/login`; API reads/writes → `401 Authentication is required.`; signed-in session → `200` with user + CSRF | **Passed (retest verified)** |
| TC-03 | Automatic alert on transaction | Stock-out did not trigger automatic low-stock alerts. *(equivalent: publish did not trigger alerts)* | Created price record #64 (pending) and approved it; watched alert fan-out | Approve returned `sms: {sent: 2, failed: 0, subscribers: 2, eligible: 2}`; 2 rows in `sms_subscriptions.last_alert_at` updated to `2026-10-14 20:58:10`; 2 new lines appended to `api/tmp/logs/sms-sim.log` at `20:58:10` | **Passed after correction (retest verified)** |
| TC-04 | Audit logging of state changes | Discrepancy calculated and saved to audit log with status. *(equivalent: changes saved to audit log with status)* | Queried `audit_logs` for records created during this retest | Rows 706 `create`, 707 `approve` (price_records #64); 708 `create`, 709 `update`, 710 `delete` (users #6), each with old/new values, user id and timestamp | **Passed (retest verified)** |
| TC-05 | Forgot Password | Reset token mismatch prevented reset link validation. | Probed `POST /api/auth/forgot` and `/api/auth/reset`; grepped codebase for reset-token code | Both endpoints → `404 Not found.`; no forgot/reset implementation exists in this system | **Not implemented (no feature to test) — Pending Retest if the feature is added** |
| TC-06 | Responsive Design | Sidebar hid on small screens; hamburger menu opened it. | Chromium at 375×812 and 376×812: measured sidebar/hamburger computed styles, clicked hamburger, captured screenshots | Sidebar off-canvas (`translateX(-224px)`), hamburger `display: grid`; after click: `body.admin-shell.sidebar-open`, sidebar `left: 0`, backdrop `visible / opacity 1`; screenshots captured | **Passed (retest verified)** |
| TC-07 | Manage Users | Create, edit role, and delete operations worked. | Full CRUD cycle via API with admin session: create → edit → delete → guards | Create `201` (user #6), edit `200` + persisted, delete: **initially `405 Method not allowed` — defect found**, see §2; after fix delete `200` (`is_active: 0`), guards verified | **Passed after correction (retest verified)** |

---

## 2. Defect Found and Corrected During This Retest

### DEF-01 — User Deactivate button returns 405

- **Detected:** TC-07 retest, 2026-10-14 21:00.
- **Observed:** The UI's Deactivate action (`js/users.js` → `window.api.del('users/{id}')`) sends `DELETE /api/users/{id}`, but `api/handlers/users.php` had no DELETE branch — it accepted only GET/POST/PUT/PATCH, so every deactivate attempt failed with `405 {"detail":"Method not allowed."}`. The button could never work. (No prior `delete` audit rows exist for `users`, confirming the path never succeeded.)
- **Correction:** Added a `DELETE` branch and a `deactivate_user()` function in `api/handlers/users.php` implementing the soft-delete the UI promises: sets `is_active = 0` (account history preserved), writes a `delete` audit row with old/new values, returns `422` when deactivating your own account, `404` for unknown ids, and remains behind `require_auth` + CSRF.
- **Retest after correction (2026-10-14 21:04):**
  - `DELETE /api/users/6` with admin session → `200`, `is_active: 0` (verified in DB).
  - Audit row 710: `delete | users | 6 | {"is_active":1} → {"is_active":0,...}`.
  - Self-deactivate (`DELETE /api/users/1` as user 1) → `422 "You cannot deactivate your own account."`
  - Unauthenticated `DELETE` → `401 Authentication is required.`
  - **Status: Passed after correction (retest verified).**


---

## 3. Detailed Test Cases

### TC-01 — User Login

- **Testing action:** `POST /api/auth/login` three ways: (a) unknown email + wrong password, (b) valid email + empty password, (c) valid credentials; then verified the session with `GET /api/auth` and an authenticated page load.
- **Initial finding (original):** Invalid credentials did not display an error message.
- **Retest evidence:**
  - (a) `HTTP 401` → `{"detail":"Invalid email or password."}`
  - (b) `HTTP 422` → `{"detail":"Validation failed.","errors":{"password":"Password is required."}}`
  - (c) Valid credentials (admin reseeded via `database/seed.php` with `SEED_ADMIN_PASSWORD` from `.env`, 2026-10-14 21:42): `POST /api/auth/login` → `HTTP 200` with user object + CSRF token; the session cookie was then accepted by `GET /api/auth/me` (`200`, `last_login: 2026-10-14 21:42:29`) and by an authenticated page load (`GET /settings` → `200`); `POST /api/auth/logout` → `200` and the same cookie was rejected afterwards (`/api/auth/me` → `401`).
  - Correction made during this follow-up: `database/seed.php` required `config/config.php` at the project root (stale paths from before the `api/` reorganisation) and fatally errored; it now requires `api/config/env.php`, `api/config/config.php` and `api/config/database.php`. `php -l` clean and a full seed run succeeds, so the documented seeding flow works again.
- **Final status:** **Passed after correction (retest verified)** — all three credential paths (invalid, empty, valid) exercised end to end, including session establishment, authenticated access and logout invalidation.

### TC-02 — Access Control (unauthenticated vs authenticated)

- **Testing action:** Unauthenticated requests to workspace pages, API reads and API writes; then confirmed a genuine signed-in session is accepted.
- **Retest evidence:**
  - `GET /MPDIS/app.php` → `302` → `http://127.0.0.1/MPDIS/login`
  - `GET /MPDIS/dashboard` → `302` → `http://127.0.0.1/MPDIS/login`
  - `GET /api/users` → `401 Authentication is required.`
  - `DELETE /api/users/1` → `401 Authentication is required.`
  - `POST /api/price-records` → `401 Authentication is required.`
  - Probe session `GET /api/auth/me` → `200` with user object + CSRF token.
- **Final status:** **Passed (retest verified).** Note: this system has a single Super Admin role (no per-role page matrix), so role-separation testing is not applicable to this codebase.

### TC-03 — Automatic Alert Trigger on Publish (equivalent of stock-out low-stock alert)

- **Testing action:** Created price record #64 (Green Grams, Kisumu Central, KES 155.50, status `pending`) via `POST /api/price-records`, recorded `last_alert_at` and sim-log size, then approved it via `POST /api/price-records/64/approve` and re-measured.
- **Retest evidence:**
  - Before: subscriber 168 `last_alert_at = 2026-10-08 13:29:19`, subscriber 169 `NULL`.
  - Approve response: `"sms":{"sent":2,"failed":0,"simulated":true,"subscribers":2,"eligible":2,"claimed":2,"skipped":0,"restore_failures":0}`.
  - After: both subscribers `last_alert_at = 2026-10-14 20:58:10`.
  - `api/tmp/logs/sms-sim.log` gained exactly two lines: `[2026-10-14T20:58:10+03:00] TO +254792077239` and `... TO +254741201983`.
  - Alerts fired exactly once (on the pending → published transition); no duplicate sends.
- **Final status:** **Passed after correction (retest verified).** Gateway was in simulation mode; a live-gateway run is a separate, provider-dependent test (Pending if real SMS delivery must be evidenced).

### TC-04 — Audit Logging

- **Testing action:** Queried `mpids.audit_logs` for every state change made during this retest session.
- **Retest evidence:**
  - 706 `create | price_records | 64 | 2026-10-14 20:58:00`
  - 707 `approve | price_records | 64 | 2026-10-14 20:58:10`
  - 708 `create | users | 6 | 2026-10-14 21:00:31`
  - 709 `update | users | 6 | 2026-10-14 21:00:53` (old/new full-name values retained)
  - 710 `delete | users | 6 | 2026-10-14 21:04:00` (`{"is_active":1}` → `{"is_active":0}`)
  - Append-only behaviour confirmed (no updates/deletions of prior rows during the session).
- **Final status:** **Passed (retest verified).**

### TC-05 — Forgot Password

- **Testing action:** Probed both endpoints and searched the codebase.
- **Retest evidence:** `POST /api/auth/forgot` → `404 Not found.`; `POST /api/auth/reset` → `404 Not found.`; no `reset_token` / `password_reset` / forgot-password code exists anywhere in `api/`, `app.php`, `js/` or the HTML pages.
- **Final status:** **Not implemented** in this system. Recorded as **Pending Retest** if the feature is ever added; the original "token mismatch" defect cannot be reproduced against a feature that does not exist here.

### TC-06 — Responsive Design

- **Testing action:** Chromium at 375×812 and 376×812 on `/MPDIS/dashboard` with a signed-in session; measured computed styles before/after clicking `[data-sidebar-toggle]`; screenshots captured.
- **Retest evidence:**
  - Closed state: `.sidebar` `transform: translateX(-224px)` (off-canvas), hamburger `[data-sidebar-toggle]` `display: grid` (visible), content stacked single-column.
  - Open state after click: `body` class `admin-shell sidebar-open`, `.sidebar` `left: 0` (on-screen), backdrop `.sidebar-backdrop` `visibility: visible; opacity: 1`.
  - CSS rules confirmed in `css/responsive.css` inside `@media (max-width: 820px)`: `.sidebar { transform: translateX(-100%) }` and `.sidebar-open .sidebar { transform: translateX(0) }`.
  - Screenshots: mobile dashboard (sidebar hidden) and mobile dashboard with sidebar open over dimmed backdrop captured during this retest.
  - Note: the preview panel sometimes composited a stale frame mid-transition; final state was confirmed via computed styles and the captured post-repaint screenshots.
- **Final status:** **Passed (retest verified).** Viewport sizes and browser recorded above, as the original checklist requested.

### TC-07 — Manage Users

- **Testing action:** Full lifecycle against the live API with an admin session and CSRF token: create `TC07 Retest User` → edit name → delete (deactivate) → verify persistence → verify audit → guard checks.
- **Retest evidence:**
  - Create → `201` `{id: 6, ...}` at 21:00:31 (audit 708).
  - Edit → `200`, name persisted as `TC07 Retest User Edited` (audit 709).
  - Delete → **`405 Method not allowed` (DEF-01 found)** → corrected → retest `200` with `is_active: 0` (audit 710), row remains for history.
  - Guards after correction: self-deactivate → `422`; unauthenticated → `401`; unknown id → `404`.
  - Test account #6 (`tc07-retest@mpids.local`) intentionally left in the database, deactivated, as retest evidence.
- **Final status:** **Passed after correction (retest verified).**

---

## 4. Evidence Captured (all genuine, from this retest run)

1. HTTP transcripts of TC-01/TC-02/TC-03/TC-05/TC-07 (status codes + JSON bodies) from `curl` against `http://127.0.0.1/MPDIS/`.
2. Database snapshots: `sms_subscriptions.last_alert_at` before/after; `audit_logs` rows 706–710; `users` row 6 state transitions.
3. SMS simulation log: `api/tmp/logs/sms-sim.log` lines timestamped `2026-10-14T20:58:10+03:00` (the two alert sends).
4. Browser screenshots at 375/376 px viewport: dashboard with hidden sidebar; dashboard with open sidebar + backdrop (TC-06).
5. Source evidence for DEF-01: `js/users.js` Deactivate handler → `api/handlers/users.php` (before/after), `php -l` clean.
6. TC-01 valid-login transcripts (2026-10-14 21:42): login `200`, `/api/auth/me` `200` with `last_login` stamp, `/settings` `200` while authenticated, logout `200` → subsequent `401`.

**Not captured (stated honestly):** no screenshots of a successful password-form login were needed because the valid-credential path was verified with HTTP transcripts, not screenshots (working password unavailable earlier — now reseeded and verified as TC-01 above); no real-SMS delivery evidence (simulation mode); no tester screenshots for TC-04 beyond DB rows.

## 5. Final Verification Checklist (status after this run)

- ✅ Actual date/time, environment, and tester recorded for each execution (header table + per-case evidence).
- ✅ Genuine evidence attached/captured for TC-01 (negative cases), TC-02, TC-03, TC-04, TC-06, TC-07.
- ✅ Each corrected defect (DEF-01) was rerun after the fix and the actual retest outcome recorded.
- ✅ Initial failing result preserved in history (TC-07 `405` retained in §2 and §3; original findings retained in §1).
- ✅ Tests not rerun recorded as **Pending Retest**, not Passed: TC-05 (feature not implemented); live-gateway SMS delivery. TC-01's previously pending valid-credential path was completed on 2026-10-14 21:42 (see §3 TC-01).
- ✅ No real passwords, reset tokens, or secrets included in this log (CSRF token and session cookie values omitted; the reseeded admin password lives only in `.env` / the operator's records, never in this document).
- ✅ Throwaway verification scripts removed from tree: `_sessprobe.php`, `mpids_read_site_settings_probe.php`, `MPIDS_runtime_verification.md` are no longer present in the project root, so they cannot be deployed or exposed. `/_sessprobe.php` now returns `404`.

## 6. Corrections Applied to the Codebase During This Run

| File | Change |
|---|---|
| `api/handlers/users.php` | Added `DELETE /api/users/{id}` handling via new `deactivate_user()` (soft-delete with `is_active = 0`, audit row, self-deactivate guard, 404 for unknown ids). Verified with `php -l` and live retest. |
| `database/seed.php` | Fixed stale require paths (`config/…` → `api/config/…`, added `env.php`) — the seeding script previously fataled on every run. Verified by a successful seed and the TC-01 valid-login retest that followed. |
| `.htaccess`, `settings.html` | `/settings` now routes through `app.php?view=settings` like every other admin page (signed-out → `302 /login`; signed-in → real Settings view); the static "Logged-out preview" placeholder was removed. Verified live in both states. |
| `_sessprobe.php` | Throwaway session-probe script from earlier verification runs deleted; `GET /_sessprobe.php` now returns `404`. |
| `_sessprobe.php`, `mpids_read_site_settings_probe.php`, `MPIDS_runtime_verification.md` | Three throwaway verification scripts left in the project root have been removed from the repo so they cannot be deployed or exposed. `GET /_sessprobe.php` now returns `404`. |

*Note for documentation use: every status in this log is backed by a retest executed on 2026-10-14 against the running local system. No test dates, tester names, screenshots, or evidence references were invented.*

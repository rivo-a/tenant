# Response to the external audit

Audit revision: `e2c43b2` — older than the current working tree, so several findings were already
closed before this pass. Each line below states the status **against the current code**, not against
the audit's revision. Nothing is marked fixed without a test or a render behind it.

## Fix before deployment

| Audit finding | Status | What changed / evidence |
|---|---|---|
| Credentials and tenant records committed to Git (Critical) | **Partially fixed** | `.gitignore` added; `.env`, `database/tenant_system.db`, `.sqbpro`, `storage/logs/*.log`, `write_test.php` and a stray duplicate env file removed from the index (files intact on disk, verified). **Still open:** history purge and credential rotation — destructive, needs your approval. |
| Legacy room pricing and DB scripts lack guards (High) | **Fixed** | `public/room_types.php` rewritten: requires sign-in + `rooms.manage`, ownership check per template (shared templates are platform-owned), POST-only mutations with CSRF, no `display_errors`. Collector receives 403, landlord renders. `public/bbc.php` and `public/cre.php` moved to `database/migrations/legacy/` — no longer web-reachable. |
| Payment verification has no independent approval (High) | **Fixed for separation, code UI unchanged** | `payments.created_by` added; `confirmPayment()`/`markPaymentDone()` accept actor + `allowSelfApproval`. A property manager cannot approve a payment they recorded; a landlord still can. Verified: refusal leaves the row `pending`, different reviewer succeeds, legacy rows with unknown recorder stay approvable. The shared daily code is still displayed in the page (not changed). |
| Audit Logs misses key staff actions (High) | **Already fixed earlier** | `logAudit()` now writes the real `audit_logs` columns through `core/audit.php`; live `STAFF_CREATED`, `PAYMENT_CONFIRMED`, `LOGIN`, `ACCESS_DENIED` rows confirmed. |
| Account restrictions only partly enforced (High) | **Mostly fixed** | Tenant guard re-reads status/password requirement every request (earlier); checkout now revokes portal access and issued tokens; staff sign-in now rejects `is_active = 0` before creating a session; role is read from the database, not the session (earlier). **Still open:** staff sign-in throttling. |
| Several write operations lack CSRF (High) | **Mostly fixed** | `rooms.php`, `tenant_exit.php`, `room_types.php` now verify tokens server-side; `admin_management.php`, `properties.php`, `payments.php`, `maintenance_requests.php` already did. The shared navbar injects a token into any legacy POST form that lacks one, so older forms submit verifiable tokens. Pages without a server-side `verify_csrf` call still accept a missing token (tracked below). |
| Sensitive pages and uploads need safeguards (Medium) | **Mostly fixed** | Maintenance error display removed, MIME sniffed from file content, single upload path, attachments served only through an authorised endpoint (earlier); portal dashboard CDN script removed (earlier); CSV export now prefixes `= + - @` and control characters with an apostrophe to stop spreadsheet formula injection. |

## Broken or misleading workflows

| Audit finding | Status | Notes |
|---|---|---|
| Same tenant has three different balances (High) | **Mostly fixed** | Staff roster, dashboard, reports, caretaker list, portal, statement and receipt all read `ArrearsService`. Tenant 16 reads 1,310,000 on every one of those surfaces. **Still open:** `remind.php` computes its own current-month figure. |
| Tenant maintenance cannot submit (High) | **Fixed** | Page rebuilt around `MaintenanceService`; submit, list, timeline, attachments and landlord inbox verified. |
| Staff account management broken (High) | **Fixed** | Rewritten against the real schema (`password`, plus new `is_active`); creation verified live by the account owner. |
| Checkout ignores the arrears confirmation (High) | **Fixed** | CSRF enforced; exit date validated (not future, not before move-in); balance computed by `ArrearsService` **at the exit date**; unconfirmed arrears block the exit unless the box is ticked; `tenant_exits` row persists date, rent, billed, paid, balance, reason and actor in the same transaction; portal access and tokens revoked. Tested: no token → CSRF refusal; token without confirmation → "still owes". |
| Rent changes erase negotiated prices and recalculate earlier months (High) | **Partly fixed** | Earlier months are now immutable via `rent_charges` (verified: raising rent left past expected and balance unchanged). **Still open:** `clear_room_tenant_rent_snapshots()` still clears explicit tenant overrides, because the schema cannot distinguish an override from an inherited value. |
| Tenant Payments link 404 (Medium) | **Fixed** | `public/portal/payments.php`, `receipt.php`, `statement.php` built; isolation tested (other tenant 404). |
| Room duplicates and ambiguous tenant logins (Medium) | **Open** | No unique constraint on room number per business, no phone normalisation. No duplicates exist today; the constraint needs a product decision on scope. |
| Verification list silently drops rows after 100 (Medium) | **Open** | Loads all rows then slices in PHP. |
| Deployment paths and build outputs inconsistent (Low) | **Open** | Hardcoded `/tenant-system/public`, index router references a missing logout, `build:css` output differs from the served asset. |

## Unfinished areas

| Area | Status |
|---|---|
| Tenant maintenance staff inbox | **Done** (landlord/property-manager screen, assignment, status, notes, private attachments). |
| Tenant portal payments history | **Done.** Staff-assisted recovery/reset remains open. |
| Leases and billing schedule | **Partly**: `rent_charges` gives per-month billed rent, but there is no lease lifecycle, schedule generation or caretaker-check workflow. |
| Payment corrections | **Partly**: `reversed` state exists and is excluded from income; a distinct `rejected` state and a correction UI remain open. |
| Reminders | Balance now read from `ArrearsService` (preview and send recheck). Still manual preview/send only, provider URL hardcoded, no scheduler. |
| Reproducible setup, tests, CI | **Open**: no PHPUnit suite and no CI. Dependency advisories (`setasign/fpdi`, `source-map-js`) not bumped. |

## Purge and rotation — 22 Nov 2026

Run after approval, on `main`.

- A full safety bundle (`repo-backup.bundle`, 66 MB, every ref and object) was taken before rewriting.
  It is outside the repository, in the machine's temp folder.
- History rewritten with `git filter-branch` across all 20 commits: `.env`, `database/tenant_system.db`,
  `database/tenant_system.sqbpro`, `storage/logs/`, `write_test.php` and two junk files with pasted
  shell text removed. `origin/main`, `refs/original/*` and the stash were dropped, reflogs expired and
  `git gc --prune=now` run.
- Verified: `git log --all -- .env` and the same for the database and logs return zero commits;
  `git rev-list --objects --all` contains no such blobs; only `refs/heads/main` remains. The working
  database still holds 18 tenants, 429 payments, 39 rooms, `PRAGMA integrity_check` = ok.
- **The rewrite deleted the tracked `.env` and database from the working tree.** Both were recovered
  from the bundle and re-verified (counts and integrity above). `.env` is now an untracked, ignored
  file.
- `PAYMENT_VERIFICATION_SECRET` rotated to a fresh 64-hex value. It had been blank, which is why the
  verification queue reported "not configured" and daily codes could not be derived.
- **Still yours to do:** the remote GitHub repository still holds the old history until you force-push
  (`git push --force origin main`), and `SMS_UG_API_KEY` must be rotated at SMS.UG — I cannot rotate a
  provider credential from here, and rotating it locally would break SMS until the dashboard matches.

### Fallout fixed the same day

`payments.php` was showing a bare "Something went wrong." Three faults stacked:

1. `storage/logs/` existed only as ignored content, so the rewrite removed the directory. The logger
   then failed to create `app.log`, its own write error replaced the real exception, and the user saw
   the generic 500 text with nothing recorded.
2. The database restored from the bundle was the **committed** copy, which predated migrations 005–007.
   `rent_charges` did not exist, so `tenant_filter.php` (used by payments) threw
   `no such table: rent_charges`.
3. Nothing surfaced either fault because the log write was suppressed with `@`.

Fixes: `log_error()` now creates the directory and falls back to PHP's own `error_log()` when the file
cannot be written; `storage/logs/.gitkeep` is tracked (contents still ignored) so a clone or deploy has
a writable directory; migrations 005, 006 and 007 were re-applied (notifications, 112 backfilled
`rent_charges`, `tenant_exits`, `payments.created_by`).

Verified afterwards: payments, dashboard, reports, verification queue, properties, reports centre,
maintenance, rooms, tenants, room types and staff accounts all render with zero logged exceptions, and
the portal dashboard, payments, statement, notifications and maintenance pages render with the balance
present.

**Data caveat:** the restored file is the state as of the last commit, so anything written to the
database between that commit and the purge would be missing. Row counts match the pre-purge figures
(18 tenants, 429 payments, 39 rooms), but this is worth a glance from you.

## Closed after the purge

| Item | Status |
|---|---|
| `remind.php` owned its own balance | **Fixed**: preview and send-time recheck both read `ArrearsService`; no `rent_schedule` query remains. |
| Verification queue dropped rows past 100 | **Fixed**: paging pushed into SQL (`LIMIT/OFFSET` + `COUNT`), 40 per page with Previous/Next and "Showing x–y of n". |
| Room rent changes wiped negotiated rents | **Fixed**: `tenants.rent_is_override`, set when a rent is deliberately saved and cleared when the field is left blank. Room and room-type changes skip overrides. Verified both ways at room and room-type level. |
| Duplicate room numbers | **Fixed**: unique `(admin_id, room_number)` index; the add-rooms form reports the clash instead of inserting a twin. Same number in another business still allowed. |
| Ambiguous tenant logins | **Fixed**: `phone_normalize()` plus `tenants.phone_normalized`, unique per business. `0772…`, `+256772…` and `256772…` resolve to one account; a number registered in two businesses is refused rather than guessing. 17 rows backfilled, no duplicates. |

## Remaining work, in the order I would do it

1. **Force-push the rewritten `main`** and rotate `SMS_UG_API_KEY` at the provider (blocking).
2. Server-side CSRF verification on the remaining legacy POST handlers.
3. PHPUnit suite + CI, then dependency bumps (fpdi, source-map-js).
4. `rejected` payment state and a correction screen.
5. Staff sign-in throttling.
6. Base-path configuration, single build output, real logout target in the index router.

## What I could not verify

- Manager/employee flows **in a browser**. All checks here are per-request simulations against the real
  pages and services plus direct service tests; no browser session was driven.
- Live SMS sending and provider delivery (out of scope, unchanged).
- Whether any deployed copy has the pre-existing `.env`; rotation is still required regardless.

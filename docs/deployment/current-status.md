# Current status

As of 22 November 2026. Companion to [audit-response.md](../security/audit-response.md), which maps
every audit finding to fixed, partial or open.

## Repository

| Item | State |
|---|---|
| Branch | `main`, clean working tree |
| Head | `bf26aee` — "Harden access control, unify arrears, and repair reporting" |
| Remote | `origin` = https://github.com/rivo-a/tenant.git, force-pushed, `origin/main` = `bf26aee` |
| History | rewritten on 22 Nov; the previous history (`e2c43b2` and older) is gone from the remote |
| Pre-rewrite backup | `repo-backup.bundle`, 66 MB, in the machine's temp folder — every ref and object |

Verified on the remote after the push: `git log origin/main -- .env` → 0 commits,
`database/tenant_system.db` → 0, `write_test.php` → 0. The only file tracked under `storage/logs` is
`.gitkeep`; log contents stay ignored.

Anything else that was ever in the working tree but not committed is not in the repository. The
database is deliberately untracked and lives only on the host.

## Database

| Item | State |
|---|---|
| Engine | SQLite, `database/tenant_system.db` (untracked, `.gitignore`d) |
| Schema | migrations 001–008 applied |
| Data | 18 tenants, 429 payments, 39 rooms, 112 rent charges, `PRAGMA integrity_check` = ok |
| Tables added this cycle | `properties`, `notifications`, `maintenance_requests` (+updates), `rent_charges`, `tenant_exits` |
| Columns added this cycle | `admins.is_active`, `admins.landlord_id`, `payments.created_by`, `tenants.rent_is_override`, `tenants.phone_normalized` |
| Constraints | unique room number per business; unique normalised phone per business |

Migrations to run on any other copy, in order:

```
php database/migrations/001_payment_verification.php
php database/migrations/002_staff_roles_and_properties.php
php database/migrations/003_payment_receipts_and_reversals.php
php database/migrations/004_maintenance_workflow.php
php database/migrations/005_properties_and_notifications.php
php database/migrations/006_rent_charges.php
php database/migrations/007_checkout_and_payment_actor.php
php database/migrations/008_rent_overrides_room_uniqueness_phones.php
```

## Configuration

| Item | State |
|---|---|
| `.env` | present, untracked, ignored. Holds `PAYMENT_VERIFICATION_SECRET` and `SMS_UG_API_KEY` |
| `PAYMENT_VERIFICATION_SECRET` | **rotated** on 22 Nov to a fresh 64-hex value; it had been blank, which is why the verification queue reported "not configured" |
| `SMS_UG_API_KEY` | **not rotated** — must be regenerated in the SMS.UG dashboard; rotating locally alone would break sending |
| `storage/logs/` | `.gitkeep` tracked so the directory always exists; logger creates it and falls back to PHP's `error_log()` if the file cannot be written |

## Verified behaviour

Checked by driving the real pages and services against a copy of the live database, not by inspection:

- Every admin page renders with zero logged exceptions: payments, dashboard, reports, verification
  queue, reports centre, properties, maintenance, rooms, tenants, room types, staff accounts.
- Portal pages render with the balance present: dashboard, payments, statement, notifications,
  maintenance.
- Arrears agree across dashboard, reports, roster, portal and reminders — one tenant reads the same
  figure everywhere (verified: tenant 16, balance 1,310,000 of 1,980,000 expected).
- Payment approval separation: an employee cannot approve a payment they recorded; a landlord can;
  legacy rows with no recorder stay approvable.
- Checkout: CSRF refused without a token; arrears block the exit unless confirmed; exit persisted,
  portal access and tokens revoked.
- Rent history: raising the room rent left past months unchanged; negotiated overrides survive room and
  room-type changes; inherited snapshots still clear.
- Verification queue paging happens in SQL; 40 per page, correct totals, no overlap between pages.
- Room numbers are unique per business (same number allowed in another business); phones normalise so
  `07…`, `+2567…` and `2567…` reach one account, and duplicate phones are rejected.

Not verified: anything in a real browser session (all checks are per-request simulations), and live SMS
delivery.

## Known open items

| Area | Item |
|---|---|
| Security | Server-side CSRF verification on the remaining legacy POST handlers (the navbar injects tokens, but not every page checks) |
| Security | Staff sign-in throttling |
| Payments | Distinct `rejected` state and a correction screen |
| Deploy | Hardcoded `/tenant-system` base path; index router points at a missing logout; `build:css` output differs from the served asset |
| Quality | No PHPUnit suite, no CI |
| Dependencies | Advisories not yet bumped (`setasign/fpdi`, `source-map-js`) |
| Mobile | See [android-app-review.md](../mobile/android-app-review.md): the debug APK points at `example.com`, blocks cleartext, and has no file chooser or download listener |

## Immediate next actions

1. Rotate `SMS_UG_API_KEY` at the provider and update `.env`.
2. Decide the mobile route: HTTPS hosting, or LAN cleartext allowlist, then rebuild the wrapper —
   I can scaffold an `android/` project in this repository.
3. Remaining correctness work: `rejected` payment state, throttling, CSRF sweep, CI.

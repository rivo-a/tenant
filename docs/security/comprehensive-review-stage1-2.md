# Comprehensive review — Stage 1 discovery and Stage 2 plan

Scope: full-repository review of the tenant-management SaaS. This document separates what was
**verified by inspection or test**, what was **already remediated earlier in this engagement**, and what
still needs **confirmation** before anyone touches it.

No secrets are reproduced here. No production data was modified.

---

## 1. Already remediated and verified (earlier stages)

These are listed so they are not re-reported as open issues, and so the regression suite covers them.

| area | finding | state | evidence |
|---|---|---|---|
| Audit | `logAudit()` wrote columns that did not exist, so every admin audit write failed silently | Fixed | `PAYMENT_CONFIRMED`, `STAFF_CREATED`, `PAYMENT_REVERSED` rows written live; redaction tested |
| Authorization | 13 write routes had no role check (`payments.php`, `payment_verification.php`, `payments_ajax.php`, `rooms.php`, `reports.php`, `remind.php`, `tenant_exit.php`, `dashboard.php`, `tenants.php`, …) | Fixed | Collector denied on 15 of 16 routes; POST rejected |
| Sessions | Role was trusted from the session forever; no suspension concept | Fixed | Suspension revoked a live session on the next request; role change applied immediately |
| Fatal errors | `caretaker_overdue.php` and `remind.php` both redeclared core helpers and crashed for every role | Fixed | Pages render; `Cannot redeclare` gone |
| Portal balance | Portal computed `max(0, monthly_rent − all money paid)`, a third balance formula | Fixed | Portal, engine and `reports.php` all report 1,310,000 for tenant 16 |
| Rent maths | `rent_months_due()` billed one month too few when the process timezone was not `Africa/Kampala` | Fixed | Bare CLI and app contexts now agree |
| Staff | `admin_management.php` wrote `password_hash` and `is_active`, columns that did not exist | Fixed | Live staff creation works (collector "Moo", manager "Beng") |
| Maintenance | Debug `alert()`, browser-supplied MIME, double file save, broken attachment links, no landlord screen | Fixed | 22 checks passed; attachments served only through an authorised endpoint |

---

## 2. Confirmed findings — open

### Critical

**C1. Secrets and the live database were committed to Git.**
Verified: `.env`, `database/tenant_system.db`, `database/tenant_system.sqbpro`, `storage/logs/app.log`,
`storage/logs/hard_test.log`, `write_test.php` and a stray duplicate `.env` were all tracked, and the
repository had **no `.gitignore`**.

Remediated in this stage: `.gitignore` added, all seven paths removed from the index with
`git rm --cached` (files remain on disk). **Not yet remediated:** the history still contains them.

Consequences: anyone with repository access has your database contents and environment secrets,
including database credentials and any API keys. The database also contains 429 real payments and 18
tenant records with phone numbers.

Required actions, in order:
1. **Rotate every credential in `.env`** (database, SMS provider, mail, any API keys). Assume all are compromised.
2. Rewrite history (`git filter-repo` or BFG) to purge `.env`, `*.db`, `*.log` from every commit, then force-push.
3. Verify the remote no longer serves the old objects, and force-rotate any deploy keys.

This is destructive to history and needs your explicit approval before I run it.

### High

**H1. Rent changes rewrite history.** `ArrearsService` bills `monthsDue × current rent`. The
`rent_schedule` table exists but is empty and unused. So raising a room's rent today retroactively
increases the arrears shown for past months, and lowering it reduces historical debt. Confirmed by
reading the calculation, not assumed.

Impact: arrears reports, exits, statements and the tenant portal can all show a different past than
actually happened. This is Part 3C of your brief and is the largest remaining financial defect.

**H2. Pending payments advance the rent due date.** `PaymentService::recordPayment()` moves
`tenants.rent_due_date` forward as rows are inserted, while those rows are `pending`. An unverified
payment therefore schedules the next rent even though no money is confirmed. Your brief explicitly
forbids this (Part 3A.2).

**H3. A payment can be approved by whoever can approve, including the person who recorded it.** There is
no `created_by` on `payments`, so self-approval cannot be prevented or detected. Part 3A.4.

**H4. Employee permissions do not exist as a concept.** Roles are coarse (landlord / property manager /
rent collector). There is no per-employee permission set, no separate "record" versus "approve"
capability, and no employee permission screen. Part 2.3, 2.7, 2.8.

**H5. Tenant access tokens are never enforced.** `tenant_access_tokens` has rows only when a landlord
issues a link, but the portal authenticates purely on session and account status. Token expiry and
revocation are not checked anywhere. Part 2.11.

### Medium

**M1. Role vocabulary conflicts with this brief.** The brief defines `caretaker` = landlord with
employees, and `employee` = staff. The implementation uses `landlord`, `property_manager`,
`rent_collector`, with legacy `caretaker` aliased to `landlord`. Renaming again would break what is
already shipped and tested. Recommendation: keep the working vocabulary and add `employee` as an alias
of `property_manager`, documenting the mapping, rather than a second migration.

**M2. Obsolete controllers.** `controllers/` contains `LeaseController`, `PaymentController`,
`RoomController`, `RoomTypeController`, `TenantController` built around `owner_id` / `rooms_new` and an
`AuthService` that writes a second, stale session shape. Verified: no page in `public/` requires them —
they are dead code, not live bugs, but they are a trap for the next developer.

**M3. `reports.php` is superseded.** `reports_centre.php` replaced it and navigation points there. The
old file still exists with its own calculations, so two report implementations coexist.

**M4. No automated test suite.** Every test in this engagement was an ad-hoc script. There is no
`tests/` directory, no PHPUnit, no CI.

**M5. `vendor/` is committed.** `composer.lock` and `composer.json` are present, so `vendor` can be
built. `mpdf` is installed, which means server-side PDF is available without new dependencies.

### Low

- `database/migrations/001_payment_verification.php` is a one-off script, not part of an ordered runner.
- `storage/maintenance/` (old upload directory) may hold orphaned files.
- Tenant notifications cover maintenance only; payments and rent reminders are not wired.
- Portal login has no rate limiting on either portal or admin side.

---

## 3. Needs confirmation before changing

| item | why it needs your input |
|---|---|
| Git history rewrite + credential rotation | Destructive to history; cannot be undone. Needs explicit approval and coordination with anyone who has clones. |
| Historical rent schedules | Requires a `rent_schedule` backfill decision: from move-in using current rent, or from the next month only. Each produces different historical arrears. |
| Self-approval prevention | Needs `payments.created_by`; existing 429 rows have no reliable recorder, so they would be backfilled as "unknown". |
| Role renaming (`caretaker`/`employee`) | Breaks the tested model; recommend alias instead. |
| Whether `rent_collector` counts as an `employee` for permission purposes | Business decision, not a code one. |

---

## 4. Stage 2 remediation plan (ordered)

**Batch A — credentials and history (blocking, needs approval).**
Rotate secrets, purge from history, verify remote. Nothing else is safe until this is done.

**Batch B — rent history (highest financial value).**
Add `rent_charges` (tenant_id, admin_id, month, amount, source) and have `ArrearsService` bill from
charges instead of current rent. Backfill one charge per tenant per month from move-in using the rent
that applied then. Arrears, statements, exits and portal balances all read it. Verify: changing rent
from next month leaves past months unchanged.

**Batch C — payment integrity.**
Stop advancing `rent_due_date` for pending rows; add `rejected` as a distinct state from `reversed`;
add `created_by` and block self-approval; audit every transition; add a concurrency guard on approval.

**Batch D — employee permissions.**
Add `employee_permissions` (admin_id, capability, allowed) and make `can()` consult it. Separate
`payments.record` from `payments.approve`. Employee management screen with per-permission toggles.

**Batch E — tenant access tokens.**
Enforce token expiry and revocation on protected portal requests; revoke on exit and password change.

**Batch F — test suite.**
PHPUnit with integration tests for the workflows and security tests for isolation, CSRF, suspension.
Convert the ad-hoc probes in this engagement into permanent tests.

**Batch G — UI modernisation.**
Icon system (Lucide, vendored), sidebar navigation, consistent cards, tables, empty states, forms.
Applied after the correctness batches so the UI never masks a wrong number.

**Batch H — cleanup.**
Remove `controllers/*` and `AuthService` (dead second auth path), delete `reports.php` once approved,
add an ordered migration runner, gitignore `vendor`.

---

## 5. What was verified this stage

| check | result |
|---|---|
| Portal payment history renders (tenant 16) | PASS — 8,488 bytes, no fatal |
| Portal rent statement renders | PASS — 11,918 bytes |
| Statement closing balance equals `ArrearsService` | PASS — 1,310,000 in both |
| Statement charges equal 3 months × 660,000 | PASS — 1,980,000 |
| Receipt shows its own reference and amount | PASS — R-2026-000414, 660,000 |
| Another tenant opening that receipt | PASS — "Receipt not found", 404 |
| Sensitive files still tracked after cleanup | PASS — none |
| Files still present on disk after untracking | PASS — `.env` and DB intact |
| Web-reachable migration/diagnostic endpoints in `public/` | PASS — none found |
| Unescaped `echo $_GET/$_POST` output | PASS — none found |
| Direct interpolated request data in SQL | PASS — none found in `public/` |

Unverified and therefore not claimed: SQL injection across every query, XSS across every echo, and
Composer version currency. These need the permanent test suite from Batch F rather than a grep.

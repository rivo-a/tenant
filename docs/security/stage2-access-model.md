# Stage 2 security audit — landlord, property manager and rent collector access model

Status: investigation complete. No role, schema or auth change has been made.
Baseline: continuation of the Stage 2 audit (rent/arrears unification, CSP, audit writer).

## A. Findings

### A1. The name `caretaker` currently means "landlord / business owner" — Critical

`admins.role` is `NOT NULL DEFAULT 'caretaker'`. The live data today:

| id | username | name | role |
|----|----------|------|------|
| 1 | `admin` | Owner Name | `caretaker` |
| 2 | `adminn` | Kisakye Ronnie | `super_admin` |

Every business row is owned by a single `admin_id`:

| table | admin_id present | ownership today |
|-------|------------------|-----------------|
| tenants | yes | admin 2 = 18 |
| rooms | yes | admin 2 = 39 |
| payments | yes | admin 2 = 429 |
| room_types | yes | 5 rows with NULL admin_id |
| sms_logs, tenant_timeline, caretaker_checks | yes | — |
| leases, rent_schedule, maintenance_requests | no | — |

So `admin_id` is the business-owner scope, and the `caretaker` role is the SaaS customer (landlord),
not a field collector. `core/audit.php:107` already encodes that reading: it treats role `caretaker`
as "the owner whose business was affected". Confirmed by code, not assumed.

Note that the only populated business (18 tenants, 429 payments) belongs to the **super_admin**
account. Any role correction must move that owner deliberately, not by renaming the column value.

### A2. There is no staff tier at all — Critical

- No role other than `super_admin` and `caretaker`.
- No membership/staff table linking a person to a landlord (checked all 14 tables).
- No `properties` table; a "property" is implicit as `admin_id` + `rooms`.
- `core/permissions.php` expresses exactly one distinction: super admin vs everyone else.
- `requireCaretaker()` = `['caretaker','super_admin']`, i.e. "any logged-in admin". It is used as the
  guard on `tenants.php`, `tenant_edit.php`, `tenant_control.php` — so the tenant CRUD pages are open
  to every role.

Practical consequence: today a landlord can only create more *landlords* (isolated businesses). A
property manager or rent collector cannot be provisioned with narrower rights.

### A3. Staff account management is broken and cannot provision anyone — Critical

`public/admin_management.php` writes columns that do not exist:

- line 24: `INSERT INTO admins (full_name, username, password_hash, role, is_active)`
  → schema has `password`, and no `is_active`.
- line 96 and 154: `SELECT ... is_active` → `PDOException` on every load.
- line 76: `UPDATE admins SET is_active = ...` → same failure.

Also restricted to `super_admin` (line 7), so a landlord cannot create their own staff, and the role
picker only offers `caretaker` and `super_admin` (lines 52, 161-164).

### A4. Role is trusted from the session and never re-verified — Critical

`public/login.php:46-55` stores `admin`, `admin_id`, `admin_name`, `admin_role` at login.
`core/helpers.php:52` reads the role straight from `$_SESSION`. Nothing re-reads the `admins` row on
a later request. Therefore:

- a role change made in admin management has no effect on a live session;
- there is no suspension concept (`is_active` does not exist), so revocation is impossible;
- a deleted or demoted account keeps full access until the browser session is dropped;
- `auth/AuthService.php:28` writes only `admin_id`/`admin_role` and never `admin` — a second, stale
  session contract; `AuthService::check()` tests `$_SESSION['admin']` while pages test `admin_id`.

### A5. Write pages are not role-guarded — High

Verified guards per entry point:

| page | guard | reachable by any staff session |
|------|-------|-------------------------------|
| `payments.php` (records payments) | `require_login()` only | yes |
| `payment_verification.php` (confirms payments) | `require_login()` only | yes |
| `payments_ajax.php` (roster endpoint) | `require_login()` only | yes |
| `payments_history.php` (all payment history) | `isLoggedIn()` only | yes |
| `rooms.php` (create rooms, change rent) | `isLoggedIn()` only | yes |
| `dashboard.php` | `isLoggedIn()` only | yes |
| `reports.php` (financial reports) | `require_login()` only | yes |
| `remind.php` (sends SMS) | `require_login()` only | yes |
| `tenant_exit.php` | manual `isset($_SESSION['admin_id'])` | yes |
| `tenants.php`, `tenant_edit.php`, `tenant_control.php` | `requireCaretaker()` = everyone | yes |
| `audit_logs.php` | `requireSuperAdmin()` | no |
| `admin_management.php` | `requireSuperAdmin()` | no |

Every one of these scopes its queries by `current_admin_id()`/`$_SESSION['admin_id']`, so cross-landlord
leakage is currently prevented — but vertical privilege separation (collector vs manager vs owner) does
not exist anywhere except the two super-admin pages above.

### A6. The only "caretaker page" fatals on load and its maths is wrong — High

`public/caretaker_overdue.php` is linked from the navbar (lines 74, 102) and:

1. **Fatal error:** it declares `function current_admin_id()` unconditionally at line 14 while
   `core/helpers.php:45` already declared it (under a `function_exists` guard). Probed with a caretaker
   session:
   `PHP Fatal error: Cannot redeclare current_admin_id() ... caretaker_overdue.php on line 17`.
2. **Wrong overdue maths:** it bills one calendar month (`strftime('%Y-%m', p.payment_date) = :month`)
   and sums `p.amount` with no verification filter (lines 64-74, 81), so pending money counts as paid
   and arrears older than the current month are invisible. This is exactly the divergence
   `ArrearsService` was introduced to remove.
3. Its guard `requireRole(['caretaker','super_admin'])` grants it to the landlord and platform owner,
   which inverts the intent.

Landlord scoping (`WHERE t.admin_id = :admin_id`, line 83) is correct.

### A7. Tenant portal is structurally separate and healthy — Informational

`public/portal/*` uses its own `tenant_id` session with `tenant_access_tokens`, phone + password or
code login, and already produces `LOGIN_SUCCESS` / `LOGIN_FAILED_*` / `PASSWORD_CHANGED` audit rows.
No admin-session path reaches it and vice versa. It does not need changes for this model.

### A8. Overdue calculation consistency — Good, with one gap

`ArrearsService::getArrears()` bills every month from move-in, subtracts verified payments only and
marks overdue only when `balance > 0` **and** `rent_due_date < today`. `reports.php`, `dashboard.php`,
`payments.php` and `tenant_filter.php` share it. `caretaker_overdue.php` (A6) is the sole dissenter.
`tenant_filter.php` still uses pending money to reduce `outstanding_balance` (line 99) — deliberate for
the receive-payment flow, but it must never be the caretaker's source.

## B. Recommended access model

Capability-based checks, resolved from the database per request, with `admin_id` retained as the
ownership scope (no column rename).

| capability | platform owner | landlord | property manager | rent collector | tenant |
|---|---|---|---|---|---|
| platform admin, all businesses | yes | no | no | no | no |
| create/edit own business staff | yes | yes | delegated | no | no |
| dashboard | yes (own) | yes | yes | **no** | no |
| tenants: view all / CRUD | yes | yes | yes | **overdue only** | own record |
| rooms, room types, rent | yes | yes | yes | **no** | no |
| record payment | yes | yes | yes | **no** | no |
| verify/approve/reverse payment | yes | yes | yes | **no** | no |
| verification codes | yes | yes | yes | **no** | no |
| financial reports | yes | yes | yes | **no** | no |
| audit logs | yes | yes | **no** | no | no |
| other landlord's data | only via platform admin | no | no | no | no |

- Rent collector's data surface is exactly: tenant name, phone, room/property label, outstanding
  balance, rent period, days overdue. No amounts collected, no payment rows, no codes.
- Landing routes: `rent_collector → caretaker_overdue.php`, `landlord/property_manager → dashboard.php`,
  `super_admin → dashboard.php`.
- Session contract: single `$_SESSION['auth']` = `{admin_id, role, landlord_id, name, version}` where
  `landlord_id` is the owning business (equal to `admin_id` for a landlord). Existing
  `$_SESSION['admin_id']`/`admin_role` kept as read-only aliases for backward compatibility.

## C. Safe implementation plan (reviewable batches)

**Batch 1 — owner management repair (no role semantics change).** Fix `admin_management.php` to the real
columns, add an `is_active` column migration with default 1, scope listing by owner, allow the landlord
to manage their own staff. Restores the ability to provision anyone at all.

**Batch 2 — identity resolution (no behaviour change yet).** Add `core/auth_context.php`:
`auth_user()` re-reads the `admins` row per request, rejects inactive accounts, exposes `role`,
`landlord_id`, and bumps a session version on role/status change so stale sessions drop. Migrate
`current_admin_role()` to read it. Verify all 12 pages still behave as before.

**Batch 3 — role vocabulary + capability map.** Add `landlord`, `property_manager`, `rent_collector`
alongside existing values; map legacy `caretaker` → `landlord` at read time (no data rewrite). Data
migration to relabel owners is a separate, independently revertible step. Introduce
`require_capability('payments.write')` and apply it page by page, replacing `requireCaretaker()`.

**Batch 4 — rent collector route.** Rebuild `caretaker_overdue.php` on `ArrearsService::getOwing(...,
overdueOnly: true)` with a minimal column set and no landlord nav; redirect collectors there on login;
exclude collectors from every other page and from `payments_*.php`.

**Batch 5 — audit + denial logging.** Record `ACCESS_DENIED` with actor id, role and landlord scope, and
tag admin actions with the actor's role so a landlord's action is never attributed to a collector.

**Batch 6 — properties (only if confirmed).** If a landlord truly needs multiple named properties under
one account, add a `properties` table and `rooms.property_id`; otherwise treat the account as the
business and change nothing.

Rollback: migration files are additive (`ALTER TABLE ... ADD COLUMN`), and role mapping happens at read
time, so reverting is a code revert with no data loss. A timestamped DB copy is taken before any
migration, as in the audit batch.

## D. Verification (disposable DB + synthetic accounts)

1. Collector logs in → lands on the collector page only.
2. Collector sees overdue tenants of their own landlord.
3. Non-overdue tenants absent.
4. Collector cannot open `dashboard.php`.
5. Collector blocked on direct URLs: `payments.php`, `payment_verification.php`, `rooms.php`,
   `reports.php`, `payments_history.php`, `remind.php`, `tenant_edit.php`, `tenant_exit.php`,
   `tenant_control.php`, `tenants.php`, `audit_logs.php`, `admin_management.php`.
6. Collector rejected by `payments_ajax.php` and every POST handler.
7. Collector cannot reach another landlord's tenants by editing ids or session values.
8. Deactivated collector's live session stops working on the next request.
9. Landlord records a payment that clears the balance → tenant leaves the collector list.
10. Landlord and property manager workflows unchanged.
11. 18 tenants, 39 rooms, 429 payments and 24 audit rows intact after migration.

## Decisions taken

- Role vocabulary: **alias at read time**. Legacy `caretaker` is mapped to `landlord`; no stored
  role value was rewritten, so reverting is a code revert.
- Staff provisioning: **landlord + property manager**, where a property manager can create
  (and suspend) rent collectors only.
- Multiple properties: **a `properties` table** was added with `rooms.property_id`. The management
  UI is a separate batch; no room was reassigned or renamed.

## Implementation record

| file | change |
|------|--------|
| `database/migrations/002_staff_roles_and_properties.php` | adds `admins.is_active`, `admins.landlord_id`, `properties`, `rooms.property_id`, indexes. Idempotent; ran in a transaction after a DB copy |
| `core/auth.php` | live identity from `admins` per request, role vocabulary + alias, `current_business_id()`, session revocation, role drift sync, JSON-aware refusal |
| `core/permissions.php` | capability matrix, `require_capability()`, `deny_access()` with audited refusal; legacy helpers kept |
| `core/helpers.php` | `current_admin_role()` now reflects the database role |
| `core/bootstrap.php` | loads `core/auth.php`, enforces the session on every request |
| `public/login.php` | lands each role on its own home page |
| `public/admin_management.php` | rebuilt on real columns; landlord/PM scoping; suspend + role change; audited |
| `public/caretaker_overdue.php` | rebuilt read-only on `ArrearsService` for collectors only; minimal field set |
| `public/partials/navbar.php` | capability-gated links; collectors get one link and no menu |
| `payments.php`, `payment_verification.php`, `payments_ajax.php`, `payments_history.php`, `rooms.php`, `reports.php`, `dashboard.php`, `tenants.php`, `tenant_edit.php`, `tenant_control.php`, `tenant_exit.php`, `tenant_payments.php`, `remind.php` | `require_capability(...)` guards and `current_business_id()` scoping |
| `public/audit_logs.php` | `audit.view` guard, SQL-level business scoping, scoped filter dropdowns |

Two pre-existing fatals were found and fixed on the way: `caretaker_overdue.php` redeclared
`current_admin_id()` and `remind.php` redeclared `e()`. Both pages failed to load for every role.

## Verification performed

Tested with five synthetic accounts against the live schema (created, exercised, then deleted;
no business records were written):

| check | result |
|-------|--------|
| collector login target | `caretaker_overdue.php`, 4 overdue tenants, phone links |
| collector on 15 other routes | all `403 Access denied` |
| collector POST to `payments.php` | refused (JSON `forbidden`) |
| collector with `tenant_id`/`admin_id` tampering | same 4 rows, parameters ignored |
| collector for another landlord | 0 tenants |
| suspended collector, existing session | revoked on next request, both tested pages |
| role promoted mid-session | dashboard allowed, collector page denied immediately |
| property manager | dashboard/reports/payments/staff allowed; audit denied |
| landlord | audit allowed, scoped to 0 rows of 46 for a business with no records |
| clearing a balance (disposable DB copy) | tenant left the overdue list, balance 0 |
| audit denials | `ACCESS_DENIED` rows carry actor, role, business and path; no credentials stored |

Remaining gaps, deliberately out of this batch:

1. **No landlord provisioning UI.** The platform owner can suspend or inspect every account, but
   there is no screen that creates a new landlord business. Until that exists, a landlord account
   must be created directly in `admins` (role `landlord`).
2. **Properties have no UI.** The table and column exist; the rooms page does not yet assign a room
   to a property.
3. **No password reset for staff.** A landlord can create and suspend accounts but cannot issue a
   replacement password from the UI.
4. **`auth/AuthService.php` and `controllers/*` are a second, unused auth path.** They still test
   `$_SESSION['admin']` and bypass the capability layer. Nothing in `public/` calls them, but they
   should be deleted or redirected to `core/auth.php` before anyone wires them up.
5. **Login has no throttling**, and the tenant portal still runs its own session model (unchanged).

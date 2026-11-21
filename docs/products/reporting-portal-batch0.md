# Reporting and tenant portal — Batch 0 discovery

Scope: inspection and design only. No behaviour, schema or financial rule was changed for this batch.

## 1. Current feature inventory

### Landlord side (`public/`)

| page | guard (after Stage 2) | what it does today |
|---|---|---|
| `dashboard.php` | `dashboard.view` | room counts, upcoming and overdue rent, pending totals via `ArrearsService` |
| `reports.php` | `reports.view` | verified collections, monthly payment activity, outstanding rent, payment ledger table, print-friendly view |
| `payments.php` | `payments.record` | receive payment, arrears-based roster, pagination |
| `payment_verification.php` | `payments.verify` | pending → confirmed → done workflow with a daily code |
| `payments_history.php` | `payments.view` | payment history listing |
| `payments_ajax.php` | `payments.view` | roster JSON endpoint |
| `tenants.php`, `tenant_edit.php`, `tenant_control.php`, `tenant_exit.php` | `tenants.manage` | tenant CRUD, rent, exit |
| `rooms.php`, `room_types.php` | `rooms.manage` | rooms, rent, room type templates |
| `admin_management.php` | `staff.manage*` | staff creation, suspension, role change |
| `audit_logs.php` | `audit.view` | event history, business-scoped |
| `remind.php` | `tenants.manage` | SMS reminders |

### Tenant side (`public/portal/`)

| page | state |
|---|---|
| `login.php`, `login_process.php`, `logout.php` | working; phone + password, lockout fields on `tenants` |
| `change_password.php` | working; enforces 8 chars, updates `must_change_password` |
| `dashboard.php` | working, **but computes its own balance** (see §4) |
| `maintenance.php` | exists with upload; 0 rows in `maintenance_requests` |
| payment history, receipts, statements, notifications, profile, documents | **do not exist** |

### Services and schema that already exist

- `services/ArrearsService.php` — the authoritative arrears engine (all months since move-in, verified only counts as collected).
- `services/PaymentService.php` — recording, pending → confirmed → done, audited.
- `services/MaintenanceService.php` — request creation, MIME-checked uploads, status updates, timeline rows.
- `core/rent.php`, `core/audit.php`, `core/auth.php`, `core/permissions.php` — rent expressions, audit writer, live identity, capabilities.
- Tables: `admins`, `tenants`, `rooms`, `room_types`, `payments`, `properties` (new), `audit_logs`, `maintenance_requests`, `maintenance_request_updates`, `sms_logs`, `tenant_access_tokens`, `tenant_timeline`, `leases`, `rent_schedule`, `caretaker_checks`.
- Empty and unused today: `leases`, `rent_schedule`, `tenant_timeline`, `caretaker_checks`, `properties`, `tenant_access_tokens`, all maintenance tables.

## 2. Reporting gap analysis

| required report | state | gap |
|---|---|---|
| A. Rent collection | partial — `reports.php` ledger + monthly activity | no date-range filter, no property/room/method filter, no receipt or reference, no CSV |
| B. Outstanding and arrears | partial — outstanding table exists | no overdue buckets (1–7/8–30/31–60/61–90/90+), no due date or days-overdue column in the report, no follow-up view |
| C. Property performance | **missing** | `properties` exists but every room has `property_id` NULL, so there is nothing to group by yet |
| D. Tenant report | partial — `tenants.php` | no payment history per tenant, no consistency indicator, no exited view with balances |
| E. Payment ledger | partial — exists in `reports.php` | no "recorded by", no verifier, no pagination in the ledger, no receipt number |
| F. Expense report | **missing entirely** | no expense table, no expense concept anywhere in code |
| G. Occupancy and vacancy | partial — counts on dashboard | no rate, no per-property breakdown, no vacant room list |
| H. Tenant exit and clearance | **missing** | `tenant_exit.php` computes a settlement view but nothing is recorded or reportable |
| I. Staff activity | partial — `audit_logs.php` | not shaped as a report, no per-staff summary |

Missing infrastructure: no exports other than a print view, no pagination on the ledger, no date-range control shared across reports, no charts at all.

## 3. Tenant portal gap analysis

Present: login, lockout fields, forced password change, maintenance submission, recent payments list.

Missing: payment history page, receipts (no receipt number exists anywhere), rent statement, notification centre, documents, profile, exit-aware locking of the portal, session invalidation on password change, rate limiting on login, first-login enforcement on every page.

## 4. Financial source of truth assessment

**One engine exists and should stay authoritative:** `ArrearsService` + `core/rent.php`.

Rules it already implements: rent precedence `tenant → room → room type`; billing from move-in to now; verified payments (`confirmed`, `done`) reduce balance; pending payments reduce what the tenant owes but are never revenue; overdue requires `balance > 0` **and** `rent_due_date < today`; payments allocated to `payment_month`, not the day handed over.

**Confirmed conflict — portal dashboard (high severity).** `public/portal/dashboard.php:47` computes:

```php
$currentBalance = max(0, $monthlyRent - $totalPaid);
```

One month of rent minus *all* money ever paid. A tenant six months in arrears who once paid two months' rent shows **zero balance** in the portal while the landlord's report shows the real arrears. The tenant record's `monthly_rent` is also read raw, ignoring the room/room-type fallback. This must be replaced with `ArrearsService` for that one tenant before any portal work proceeds.

Other accuracy points:

- **Receipt numbers do not exist.** `payments` has no reference column. Receipts need a generated, stable number.
- **Duplicate counting risk:** `payments` rows are per payment, and each carries `payment_month` plus `months_paid`. Reports must sum `amount` once per row and never join through `months_paid` as if it were an allocation table.
- **Pending vs confirmed** is already separated in `reports.php` and must stay separate in every new report and in the portal.
- **Rejected/reversed:** the schema only supports `pending`, `confirmed`, `done`. There is no reversal state. A reversal needs a decision (negative payment row vs new status), not an assumption.
- **`leases`, `rent_schedule`, `tenant_timeline`, `caretaker_checks` are empty.** Reports must not present lease or schedule data as if it exists.
- **Advance payments:** because billing runs from move-in and payments are allocated to `payment_month`, an advance payment reduces today's balance. There is no explicit policy for future periods; document the current rule rather than inventing prepayment accounting.

## 5. Security and ownership assessment

- Stage 2 baseline holds: every page resolves the live account, capability-checked, business-scoped, collectors read-only.
- New findings:
  1. `public/portal/maintenance.php:13-15` forces `display_errors=1` regardless of environment — error details leak to tenants. Must be removed.
  2. `portal/dashboard.php` is guarded by `getLoggedInTenantId()` but does not enforce `must_change_password`, so a tenant can skip the forced change by navigating directly.
  3. No receipt or statement endpoint exists yet, so the "unguessable ID" requirement is still to be designed: receipts must be fetched by `payment.id` **and** `tenant_id`, never by a public token.
  4. Uploads are already MIME-sniffed, size-capped, renamed randomly, stored outside the web root with an `.htaccess` engine-off file. Good; needs an authenticated viewer, not a public path.
  5. No login rate limiting on either portal or admin login.
  6. Exports will need the same guards as pages: capability check plus business scope, audited as `REPORT_EXPORTED`.

## 6. Database change requirements

Additive only, each in its own migration:

| change | why |
|---|---|
| `payments.receipt_no TEXT` + unique index, backfilled `R-<year>-<id padded>` | receipts and ledger reference |
| `maintenance_requests`: `urgency`, `assigned_to`, `closed_at`, `resolution_note` | assignment and tracking the spec asks for |
| `notifications` (tenant_id, admin_id, type, title, body, read_at, dedupe_key UNIQUE) | notification centre without duplicate sends |
| `tenant_documents` (tenant_id, admin_id, title, kind, file_path, visible_to_tenant, created_by) | documents section |
| `tenants.portal_revoked_at` (or reuse `account_status`) | exit and revocation |
| `expenses` — **deferred** | no data exists; inventing a module now would produce empty reports with misleading zeroes |

## 7. UI improvement plan

- One report shell: filter bar (period, property, status, method, search), consistent cards, one table body per report, pagination, export menu.
- Charts as server-rendered inline SVG (no JS dependency, CSP-safe, prints correctly). Four only: expected vs collected, outstanding ageing, occupancy, collection rate trend.
- Shared "period" state across reports via query string so a link is shareable and exports match exactly.
- Portal: mobile-first, one primary action per screen, sticky summary card (balance, next due date), bottom navigation with four items.
- Reuse the existing Tailwind token set and navbar conventions; no new UI framework.

## 8. Proposed implementation order

0. **This document.**
1. **Financial defects and receipts** — portal balance via `ArrearsService`, remove `display_errors`, `must_change_password` enforcement, `receipt_no` migration and backfill. No new UI.
2. **Property assignment** — minimum UI on `rooms.php` to attach a room to a property, plus a default property per business, so property reports have real groupings.
3. **Reporting foundation** — shared `ReportService`, rent collection and arrears reports with ageing buckets, ledger with pagination and receipt numbers, CSV export with audited, scoped queries.
4. **Advanced reporting** — property performance, occupancy/vacancy, tenant and exit reports, staff activity from `audit_logs`.
5. **Portal money** — portal dashboard on `ArrearsService`, payment history, receipts, statements.
6. **Portal self-service** — maintenance hardening plus assignment, notifications, documents, profile.
7. **Verification** — the full test matrix from Part 6, on a disposable database copy.

Expense reporting (Part 1F) stays deferred until an expense module is approved; Net operating income and expense cards will be omitted rather than shown as zero.

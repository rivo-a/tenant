# Reporting and tenant portal — Batch 1 delivery record

Batch: financial correctness and receipts. Precedes any new reporting screen so reports are never
built on figures that disagree with themselves.

## Files inspected

`public/portal/dashboard.php`, `public/portal/maintenance.php`, `public/portal/partials/portal_navbar.php`,
`core/tenant_auth.php`, `core/rent.php`, `core/audit.php`, `services/ArrearsService.php`,
`services/PaymentService.php`, `services/MaintenanceService.php`, `public/reports.php`,
`public/payments.php`, `public/payment_verification.php`, schema of `payments`, `tenants`,
`maintenance_requests`, `leases`, `rent_schedule`, `tenant_timeline`, `caretaker_checks`.

## Files changed

| file | change |
|---|---|
| `database/migrations/003_payment_receipts_and_reversals.php` | adds `receipt_no`, `reversed_at`, `reversed_by`, `reversal_reason`; backfills 429 receipt numbers; unique index |
| `services/PaymentService.php` | assigns a receipt on every new payment; `reversePayment()` keeps the row and marks it `reversed` |
| `services/ArrearsService.php` | optional tenant filter; new `getTenantArrears()` for the portal |
| `core/rent.php` | `payment_reversed_sql()`; `rent_months_due()` normalises both dates to the app timezone |
| `core/tenant_auth.php` | rejects suspended/exited/deleted tenants per request; forces a first-login password change |
| `public/portal/dashboard.php` | balance from `ArrearsService`; status label; pending amount explained; receipts shown; CDN Tailwind removed; dead payments link removed |
| `public/portal/maintenance.php` | stopped forcing `display_errors=1` |
| `public/portal/partials/portal_navbar.php` | removed the link to a non-existent page; nav visible on mobile |

## Database changes

Additive, transactional, after a copy of the DB was taken:
`payments.receipt_no TEXT` (unique, format `R-<year>-<id padded>`), `payments.reversed_at`,
`payments.reversed_by`, `payments.reversal_reason`, plus indexes. No amount, date or status of a
historical payment was modified.

## Business rules affected

- **Fixed:** the portal computed `max(0, monthly_rent - lifetime_paid)`, a third balance formula that
  could show zero while the landlord's ledger showed large arrears. It now reads `ArrearsService`.
- **Fixed:** `rent_months_due()` produced a different month count when the process timezone was not
  `Africa/Kampala`, billing one month too few. Both dates are now normalised inside the function.
- **Added:** a reversal keeps the amount and receipt, changes status to `reversed`, and is excluded
  from collected income. Pending/confirmed/done rules unchanged.

## Security implications

- Exited and suspended tenants lose portal access on their next request; a temporary password can no
  longer be skipped by typing a URL.
- Error details are no longer forced visible to tenants in production.
- Reversal is scoped to the calling business, so another landlord's payment id is rejected.
- The portal no longer loads Tailwind from a CDN, which the CSP was already blocking.

## Tests executed and results

| test | result |
|---|---|
| Receipt backfill | 429 rows, 0 duplicates, amounts untouched |
| Portal balance equals engine (tenant 16) | page `UGX 1,310,000` = `ArrearsService` = `reports.php` outstanding |
| Timezone consistency | bare CLI context and app context now both bill 3 months / 1,310,000 |
| Exited tenant (15) portal request | redirected out, no page rendered |
| First-login tenant (11) portal request | redirected to change password |
| Active tenant (16) dashboard | renders 8,090 bytes, status `Overdue`, no CDN, no fatal |
| Reversal on disposable copy | verified 670,000 → 10,000; row retained with receipt and reason |
| Double reversal | refused |
| Cross-business reversal | refused |
| Production data | 429 payments, 18 tenants, audit log intact; no reversed payments created |

## Implemented but not tested

- The reversal's own audit event (`PAYMENT_REVERSED`). The call is wired through the same
  `PaymentService::audit()` helper that demonstrably wrote `PAYMENT_CONFIRMED` rows, but the
  end-to-end write was not executed because verifying it would have required deleting a test row from
  `audit_logs`, and removing audit records is not something this batch does.

## Not implemented (by decision)

Expense reporting, per your instruction. No expense cards, no NOI, no zero-filled charts.

## Limitations

1. `reversePayment()` exists as a service only — no button yet. Reporting batches will expose it.
2. Portal payment history, receipts and statements are still missing; the nav links were removed
   rather than pointing at 404s.
3. `leases`, `rent_schedule`, `tenant_timeline` and `caretaker_checks` are empty, so nothing reports on them.
4. Receipts are a string reference; there is no printable receipt page yet.
5. Portal login has no rate limiting yet.

## Rollback

- Code: revert the six files above; the aliasing and new methods are additive.
- Data: the migration only adds columns and fills `receipt_no`. `ALTER TABLE ... DROP COLUMN` is
  available if a full rollback is ever required, or restore
  `storage` copy taken before the run (`tenant_system.pre-receipts.db`).
- No financial figure was recalculated or rewritten by the migration.

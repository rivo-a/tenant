# Reporting foundation and property assignment — delivery record

Batches 2 and 3 of the reporting programme, plus tenant notifications for maintenance.
Expenses remain deferred, per the earlier decision.

## Database changes (additive, transactional, after a DB copy)

| migration | change |
|---|---|
| `005_properties_and_notifications.php` | `notifications` table with `dedupe_key` UNIQUE; property `address`/`notes` ensured; indexes on `notifications` |

`rooms.property_id` and the `properties` table came from migration `002`.

## Files added

| file | role |
|---|---|
| `services/ReportService.php` | every report figure: overview, monthly series, arrears + ageing buckets, property performance, occupancy, paginated ledger, export rows, staff activity |
| `core/charts.php` | server-rendered SVG bars, line and stacked bar; no JavaScript, prints correctly |
| `public/reports_centre.php` | consolidated reports screen: Overview, Arrears, Properties, Payment ledger, Staff activity |
| `public/report_export.php` | CSV export of the current filtered report, audited as `REPORT_EXPORTED` |
| `public/properties.php` | create, rename, delete properties; place rooms in them; remove rooms |
| `services/NotificationService.php` | tenant notifications with dedupe, feed, unread count, mark read |
| `public/portal/notifications.php` | tenant alerts screen |

## Files changed

- `services/ArrearsService.php` — rows now carry `property_id` and `property_name`, so arrears can be grouped by property.
- `services/MaintenanceService.php` — notifications on assign and on every status change, keyed by the timeline row id; `logUpdate()` returns that id.
- `public/partials/navbar.php` — Reports now points at `reports_centre.php`; Properties and Maintenance links added, capability-gated.
- `public/portal/partials/portal_navbar.php` — Alerts link with unread badge.
- `public/portal/dashboard.php` — latest three alerts with unread count.

## Business rules applied

- Collected income is `confirmed` + `done` only. Pending is shown separately and never added. Reversed is shown and excluded.
- Outstanding and overdue come from `ArrearsService`; the reporting layer does not recompute balances.
- Expected for a period bills each active tenancy's rent for the months of that period on or after move-in.
- Ageing bands are fixed and documented in `ReportService::AGE_BUCKETS`: 0–7, 8–30, 31–60, 61–90, 90+.
- Exports run through the same service and the same filters as the screen, scoped by business id.

## Tests executed (disposable copy, then discarded)

30 checks, all passing:

| area | result |
|---|---|
| Properties listed and isolated per business | PASS — 2 for business 2, 1 for business 1 |
| Room counts per property | PASS — 10 rooms across 2 properties |
| Collected equals raw ledger SQL | PASS — 2,136,560,000 both |
| Pending equals raw ledger SQL and is not inside collected | PASS — 420,000 |
| Occupied + vacant = total rooms | PASS — 16 + 23 = 39 |
| Arrears bucket amounts equal total outstanding | PASS — 2,760,000 |
| Bucket tenant counts equal total tenants | PASS — 3 |
| Arrears rows all have a positive balance | PASS |
| Property expected sums to overview | PASS — 55,900,000 |
| Property collected sums to overview | PASS — 2,136,560,000 |
| Unassigned-rooms bucket present | PASS |
| Ledger pagination has no duplicates | PASS — 10 + 10 of 429 |
| Page count correct | PASS |
| Every ledger row has a receipt number | PASS |
| Status filter returns only that status | PASS — 4 pending |
| Tenant search | PASS — 2 matches |
| Export covers the whole filtered ledger | PASS — 429 rows |
| Staff activity built from audit log only | PASS — 3 actors, 26 events |
| Notification per maintenance change | PASS — 3 |
| Completion wording | PASS |
| Other tenant sees nothing | PASS |
| Unread count, mark one, mark all | PASS |
| Duplicate notification key ignored | PASS — replaying an existing key stayed at 3 |

Render checks: `reports_centre.php` and all four sub-views, `properties.php`, `maintenance_requests.php`,
`dashboard.php`, portal `notifications.php`, `maintenance.php`, `dashboard.php` — all render with no fatal.
A rent collector is refused (403) on `reports_centre.php` and `properties.php`.

## Data-quality finding (not a calculation bug)

The collection rate reads **3,822%** on your current data because tenant 9 has payments of **8,500,000**
per month against a rent of **660,000**, and several large payments exist across many months. The reports
faithfully total the ledger; nothing is double-counted. Either those rows are demo data or a payment was
keyed with the wrong magnitude. Worth correcting before showing the dashboard to anyone.

## Limitations

1. Charts are server-rendered SVG. Chart.js was not vendored, so there are no hover interactions; the SVG
   tooltips and print output work. This satisfies the "charts from the same data, no CSP changes" goal but
   not the interactive half of your earlier choice.
2. PDF is served by the browser's print function; there is no `report_export.php` PDF branch.
3. "Recorded by" on the ledger resolves to the business account, because `payments` stores `admin_id` and not
   the acting staff member. Fixing that needs a `created_by` column on `payments`.
4. Expenses, net operating income and expense charts are absent by decision.
5. Tenant notifications cover maintenance only; payment and rent reminders are not wired yet.
6. Notifications are in-app only — no SMS or WhatsApp.
7. `reports.php` still exists and is no longer linked from navigation. It should be deleted once you are happy
   with `reports_centre.php`, but nothing was removed without your say-so.

## Rollback

Drop the `notifications` table (no other table references it), revert the six changed files, and revert
`ArrearsService` to the previous SELECT. No financial value was rewritten; properties are empty until you
create them.

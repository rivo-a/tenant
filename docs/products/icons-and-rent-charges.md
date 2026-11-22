# Icons and historical rent charges — delivery record

Two pieces: the shared icon system requested first, and Batch B from the remediation plan
(historical rent), which was the highest financial-integrity defect still open.

## 1. Icon system

**Source.** Lucide, `lucide-static@0.468.0` (ISC), 45 icons downloaded to `assets/icons/`. No CDN at
runtime; no emoji.

**Renderer.** `core/icons.php`, loaded from the admin bootstrap.

- `icon(string $name, string $class = 'h-4 w-4 shrink-0', string $label = '', array $extra = [])`
- Inlines the SVG so it inherits `currentColor` and aligns with text, with stroke width fixed at 2 for
  the whole application.
- Sizing comes from Tailwind classes, not fixed width/height attributes.
- Name is validated against `^[a-z0-9-]+$` and passed through `basename()`-style resolution, so
  `icon('../../etc/passwd')` renders nothing (tested).
- Unknown icon names render an empty string rather than breaking a page (tested).
- `$label` present → `role="img"` and `aria-label`; empty → `aria-hidden="true" focusable="false"`.

**Applied so far**

| surface | icons |
|---|---|
| Admin primary nav | Payments `wallet`, Dashboard `layout-dashboard`, Tenants `users`, Rooms `house` |
| Admin "More" + mobile menu | History `receipt`, Tenant Payments `coins`, Reports `chart-no-axes-combined`, Control `key-round`, Properties `building-2`, Maintenance `wrench`, Staff `user-cog`, Audit Logs `clipboard-list` |
| Collector nav | Overdue tenants `circle-alert` |
| Admin logout / mobile toggle | `log-out`, `menu` |
| Portal nav | My rent `house`, Payments `wallet`, Maintenance `wrench`, Alerts `bell` (with unread badge), Logout `log-out` |
| Both navbars | Consistent `gap-1.5`, min-height 44px targets, visible focus rings |

Not yet applied (UI batch G): table row actions, empty states, dashboard stat cards, form input groups,
success/error messages.

## 2. Historical rent charges (Batch B)

**Problem.** `ArrearsService` billed `months × current rent`, so changing a rent today rewrote every
past month's arrears, statements, exit settlements and portal balances.

**Change.** New table `rent_charges` (`tenant_id`, `admin_id`, `month`, `amount`, `source`, unique per
tenant+month), created and backfilled by `database/migrations/006_rent_charges.php` — 112 charges across
18 tenants, January to November 2026.

**Backfill assumption, stated explicitly.** Months before the migration are charged at the rent in force
today (tenant override → room → room-type default). The database holds no record of earlier rent, so this
is the best available evidence and cannot invent a history that was never captured. Every month billed
from now on has its own row, so no future rent change can rewrite it.

**Read path.** `ArrearsService::getArrears()` now sums recorded charges per month between move-in and the
as-of month, falling back to the current rent only for months with no charge (the current and future
months). `ReportService`, the dashboard, the portal, statements and receipts all inherit this because
they already read `ArrearsService`.

`public/tenant_filter.php` (the receive-payment roster) was updated the same way, so the roster's
`expected_total` and `outstanding_balance` use charges rather than `months × rent`.

## Tests executed

Run against a disposable copy, then discarded.

| test | result |
|---|---|
| Baseline before change | expected 1,980,000, balance 1,310,000, rent 660,000 |
| Raise room rent to 1,000,000 and clear tenant snapshot | **expected unchanged 1,980,000, balance unchanged** |
| Same tenant as of next month | expected 2,980,000 — next month bills the new rent |
| Edit one month's recorded charge | expected follows the recorded charge (1,820,000) |
| Roster consistency after that edit | `expected_total` 1,820,000, matching the engine |
| Production values after migration | tenant 16 expected 1,980,000, balance 1,310,000 — unchanged |
| Page renders | dashboard, payments, reports, tenants, rooms, properties, maintenance all clean |
| Portal renders | dashboard, payments, statement, alerts, maintenance all clean; statement closing still 1,310,000 |
| Receipt isolation | own receipt 200, other tenant 404 |

## Bug fixed during verification

`public/portal/partials/portal_navbar.php` fatalled with `Call to undefined function icon()` because
portal pages do not load the admin bootstrap. The partial now requires `core/icons.php` and
`core/helpers.php` itself when they are absent. Caught by the render pass, not by inspection.

## Still open from the review (not started)

- **Batch C** — pending payments still advance `rent_due_date`; no `created_by` so self-approval cannot
  be blocked; `rejected` is not modelled separately from `reversed`.
- **Batch D** — per-employee permissions and separate record/approve capabilities.
- **Batch E** — tenant access-token expiry and revocation enforcement.
- **Batch F** — permanent PHPUnit suite; every test so far is an ad-hoc script.
- **Batch G** — remaining icon surfaces, sidebar navigation, table and form polish.
- **Batch H** — delete `controllers/*` and `AuthService` dead path, retire `reports.php`, ordered
  migration runner.
- **Blocking** — credential rotation and Git history purge still need your approval.

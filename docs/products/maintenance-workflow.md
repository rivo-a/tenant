# Maintenance workflow — delivery record

Tenant submission through landlord resolution, replacing a half-built flow that had no
landlord-side screen at all.

## What was broken before

| issue | effect |
|---|---|
| `alert('JS IS RUNNING!')` in the portal page | debug dialog on every page load |
| Upload validated with `$_FILES['type']` | browser-supplied MIME, trivially spoofed |
| Page moved the file, service moved it again | file saved twice, one orphan per upload |
| Attachment link pointed at `/public/storage/...` | every attachment link 404'd |
| No admin screen, no assignment, no urgency | requests were written and never seen |
| Status vocabulary split between page and service | `acknowledged`/`resolved` vs the filter list |

## Files changed

| file | change |
|---|---|
| `database/migrations/004_maintenance_workflow.php` | `admin_id`, `urgency`, `assigned_to`, `closed_at`, `resolution_note`; status normalisation; indexes |
| `services/MaintenanceService.php` | rewritten: tenant create/list/detail, business list/detail, assignment, status transitions, timelines, MIME-sniffed uploads, scoped attachment resolution, audit |
| `public/portal/maintenance.php` | rewritten: categories, urgency, photo/PDF, status chips, timeline per request, mobile-first panel |
| `public/maintenance_requests.php` | **new** landlord/property-manager screen: filters, summary counts, assign, move status, note, attachment and call links |
| `public/maintenance_attachment.php` | **new** authenticated file endpoint (tenant owner or business staff only) |
| `public/partials/navbar.php` | Maintenance link for landlords and property managers |

## Status vocabulary

`submitted → under_review → assigned → in_progress → completed` (or `rejected`).
Legacy `acknowledged` maps to `under_review`, `resolved` maps to `completed`.

## Security decisions

- A request stores `admin_id` from the tenant, so the business boundary is a column, not a join guess.
- Every read, assignment and status change is filtered by that `admin_id`; a guessed request id from
  another business returns 404.
- Assignment only accepts active staff of the same business.
- Attachments live outside the web root with an `.htaccess` deny file; they are served only through
  `maintenance_attachment.php`, which authorises tenant-owner or business staff and re-sniffs the MIME
  type before streaming.
- Uploads are accepted by detected MIME, not by the client-declared type, capped at 5MB, and stored
  under a random name with an extension derived from the MIME.
- Rent collectors hold no maintenance capability, so they cannot reach the landlord screen.

## Tests executed and results

Run against a disposable copy of the database with synthetic requests:

| test | result |
|---|---|
| Create valid request | PASS — status `submitted`, `admin_id` 2, urgency `urgent` |
| Invalid category | PASS — refused |
| Description under 10 chars | PASS — refused |
| Tenant 16 sees only own requests | PASS |
| Tenant 17 sees only own requests | PASS |
| Tenant 17 opens tenant 16's request | PASS — null |
| Landlord 2 sees both requests | PASS |
| Landlord 1 sees nothing / opens nothing | PASS |
| Assign to own staff | PASS — status becomes `assigned`, timeline entry added |
| Assign to another business's staff | PASS — refused |
| Reject without a reason | PASS — refused |
| In progress → completed | PASS — `closed_at` stamped, resolution note stored |
| Same status twice | PASS — refused |
| Timeline | PASS — 4 entries, actors `tenant` and `admin` |
| Audit events | PASS — `MAINTENANCE_SUBMITTED`, `MAINTENANCE_ASSIGNED`, `MAINTENANCE_STATUS_CHANGED` |
| Upload stored | PASS — random filename, `.png` |
| Owner reads attachment | PASS |
| Other tenant reads attachment | PASS — null |
| Other landlord reads attachment | PASS — null |
| Text file disguised as PNG | PASS — refused by MIME sniff |
| Portal page renders (tenant 16) | PASS — 9,997 bytes, no fatal |
| Landlord page renders | PASS — 15,269 bytes, empty state |
| Rent collector reaches landlord page | PASS — 403 |
| Production data | 0 requests, 0 updates, 0 maintenance audit rows — nothing written to live records |

## Bug fixed during testing

`updateStatus()` used `CASE WHEN :has_note = 1` to decide whether to store a resolution note. PDO binds
parameters as strings in this codebase, and `'1' = 1` is false in SQLite, so the note and the closed
timestamp were silently dropped. The statement is now assembled in PHP instead of comparing a bound
parameter to a number.

## Not done / limitations

1. The attachment endpoint is authorised at service level and linted, but not exercised over HTTP —
   there is no running web server in this environment to make a real request against.
2. No tenant notifications are generated yet; tenants see changes when they open the portal. The
   notification centre is Batch 6 in the reporting/portal programme.
3. Maintenance categories are a fixed list in `MaintenanceService::CATEGORIES`; making them
   landlord-configurable needs a settings table.
4. No photo compression or thumbnails; images are served at full size.
5. `storage/maintenance/` from the old implementation may still hold orphaned files if anything was
   uploaded before this change. Nothing references it now.

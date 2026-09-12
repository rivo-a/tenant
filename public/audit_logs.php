<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();
requireSuperAdmin();

$pdo = getDB();

$action = is_scalar($_GET['action'] ?? null)
    ? trim((string)$_GET['action'])
    : '';

$admin = filter_var($_GET['admin'] ?? 0, FILTER_VALIDATE_INT);
$admin = $admin === false ? 0 : max(0, (int)$admin);

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;

$where = [];
$params = [];

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

if ($action !== '') {
    $where[] = 'al.action = :action';
    $params[':action'] = $action;
}

/*
 * Administrator filtering:
 *
 * audit_logs does not have admin_id.
 * It uses:
 *
 *   actor_type
 *   actor_id
 *
 * For administrator activity, actor_type should be "admin"
 * and actor_id should match admins.id.
 */
if ($admin > 0) {
    $where[] = 'al.actor_type = :actor_type';
    $where[] = 'al.actor_id = :admin_id';

    $params[':actor_type'] = 'admin';
    $params[':admin_id'] = $admin;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| Count
|--------------------------------------------------------------------------
*/

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM audit_logs al
     {$whereSql}"
);

$countStmt->execute($params);

$total = (int)$countStmt->fetchColumn();

$totalPages = max(
    1,
    (int)ceil($total / $limit)
);

$page = min($page, $totalPages);

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| Audit logs
|--------------------------------------------------------------------------
|
| Actual schema:
|
| audit_logs.id
| audit_logs.actor_type
| audit_logs.actor_id
| audit_logs.action
| audit_logs.target_type
| audit_logs.target_id
| audit_logs.details
| audit_logs.ip_address
| audit_logs.created_at
|
| admins.id
| admins.username
| admins.full_name
|
*/

$logsStmt = $pdo->prepare(
    "SELECT
        al.id,
        al.actor_type,
        al.actor_id,
        al.action,
        al.target_type,
        al.target_id,
        al.details,
        al.ip_address,
        al.created_at,

        a.username,
        a.full_name

     FROM audit_logs al

     LEFT JOIN admins a
        ON a.id = al.actor_id
       AND al.actor_type = 'admin'

     {$whereSql}

     ORDER BY al.created_at DESC, al.id DESC

     LIMIT {$limit} OFFSET {$offset}"
);

$logsStmt->execute($params);

$logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

$summary = $pdo->query(
    "SELECT
        COUNT(*) AS total_events,

        COUNT(
            DISTINCT CASE
                WHEN actor_type = 'admin'
                THEN actor_id
            END
        ) AS tracked_admins,

        SUM(
            CASE
                WHEN created_at >= datetime('now', '-1 day')
                THEN 1
                ELSE 0
            END
        ) AS recent_events

     FROM audit_logs"
)->fetch(PDO::FETCH_ASSOC) ?: [];

/*
|--------------------------------------------------------------------------
| Available actions
|--------------------------------------------------------------------------
*/

$actions = $pdo->query(
    "SELECT DISTINCT action
     FROM audit_logs
     WHERE action IS NOT NULL
       AND action <> ''
     ORDER BY action"
)->fetchAll(PDO::FETCH_COLUMN) ?: [];

/*
|--------------------------------------------------------------------------
| Available administrators
|--------------------------------------------------------------------------
*/

$admins = $pdo->query(
    "SELECT
        id,
        username,
        full_name

     FROM admins

     ORDER BY full_name, username"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/*
|--------------------------------------------------------------------------
| Pagination / filter query
|--------------------------------------------------------------------------
*/

$filterQuery = array_filter(
    [
        'action' => $action,
        'admin'  => $admin > 0 ? $admin : '',
    ],
    static fn($value): bool =>
        $value !== '' && $value !== null
);

$pageUrl = static function (int $targetPage) use ($filterQuery): string {
    return 'audit_logs.php?' . http_build_query(
        [
            ...$filterQuery,
            'page' => max(1, $targetPage),
        ]
    );
};

/*
|--------------------------------------------------------------------------
| Action badge
|--------------------------------------------------------------------------
*/

$actionChip = static function (string $value): string {
    $action = strtoupper($value);

    $tone = match (true) {

        str_contains($action, 'FAILED')
        || str_contains($action, 'DELETE')
        || str_contains($action, 'EXIT')
            => 'bg-red-50 text-red-700 ring-red-200',

        str_contains($action, 'PAYMENT')
        || str_contains($action, 'RENT')
            => 'bg-amber-50 text-amber-800 ring-amber-200',

        str_contains($action, 'LOGIN')
        || str_contains($action, 'CREATED')
        || str_contains($action, 'ONBOARDED')
            => 'bg-emerald-50 text-emerald-700 ring-emerald-200',

        str_contains($action, 'ROLE')
        || str_contains($action, 'STATUS')
            => 'bg-sky-50 text-sky-700 ring-sky-200',

        default
            => 'bg-slate-100 text-slate-700 ring-slate-200',
    };

    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset '
        . $tone
        . '">'
        . e($value)
        . '</span>';
};

$active = 'audit';

?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8" />
  <meta
      name="viewport"
      content="width=device-width,initial-scale=1"
  />

  <title>Audit Logs</title>

  <link
      rel="stylesheet"
      href="assets/css/tailwind.css"
  >
</head>

<body class="min-h-screen bg-slate-50 text-slate-900">

<?php require __DIR__ . '/partials/navbar.php'; ?>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

  <!-- Header -->
  <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">

    <div>
      <h1 class="text-2xl font-bold tracking-tight font-alt">
        Audit Logs
      </h1>

      <p class="mt-1 text-sm text-slate-600">
        Review administrator activity and sensitive system changes.
        This page is read-only.
      </p>
    </div>

    <a
        href="dashboard.php"
        class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
    >
      Back to dashboard
    </a>

  </div>


  <!-- Summary -->
  <div class="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">

    <!-- Total -->
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

      <div class="text-xs font-semibold text-slate-500">
        Total events
      </div>

      <div class="mt-2 text-2xl font-black font-alt">
        <?= (int)($summary['total_events'] ?? 0) ?>
      </div>

    </div>


    <!-- Tracked admins -->
    <div class="rounded-2xl border border-sky-200 bg-sky-50 p-4">

      <div class="text-xs font-semibold text-sky-700">
        Tracked admins
      </div>

      <div class="mt-2 text-2xl font-black font-alt text-sky-900">
        <?= (int)($summary['tracked_admins'] ?? 0) ?>
      </div>

    </div>


    <!-- Recent -->
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">

      <div class="text-xs font-semibold text-emerald-700">
        Last 24 hours
      </div>

      <div class="mt-2 text-2xl font-black font-alt text-emerald-900">
        <?= (int)($summary['recent_events'] ?? 0) ?>
      </div>

    </div>

  </div>


  <!-- Filters -->
  <form
      method="get"
      class="mb-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"
  >

    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">

      <!-- Action -->
      <div>

        <label
            for="action"
            class="mb-1 block text-xs font-semibold text-slate-600"
        >
          Action
        </label>

        <select
            id="action"
            name="action"
            class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
        >

          <option value="">
            All actions
          </option>

          <?php foreach ($actions as $availableAction): ?>

            <option
                value="<?= e($availableAction) ?>"
                <?= $availableAction === $action ? 'selected' : '' ?>
            >
              <?= e($availableAction) ?>
            </option>

          <?php endforeach; ?>

        </select>

      </div>


      <!-- Administrator -->
      <div>

        <label
            for="admin"
            class="mb-1 block text-xs font-semibold text-slate-600"
        >
          Administrator
        </label>

        <select
            id="admin"
            name="admin"
            class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
        >

          <option value="">
            All administrators
          </option>

          <?php foreach ($admins as $availableAdmin): ?>

            <option
                value="<?= (int)$availableAdmin['id'] ?>"
                <?= (int)$availableAdmin['id'] === $admin ? 'selected' : '' ?>
            >

              <?= e(
                  $availableAdmin['full_name']
                  ?: $availableAdmin['username']
              ) ?>

              (<?= e($availableAdmin['username']) ?>)

            </option>

          <?php endforeach; ?>

        </select>

      </div>


      <!-- Buttons -->
      <div class="flex items-end gap-2">

        <button
            type="submit"
            class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800"
        >
          Apply filters
        </button>


        <?php if ($action !== '' || $admin > 0): ?>

          <a
              href="audit_logs.php"
              class="inline-flex min-h-11 flex-1 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-800 hover:bg-slate-50"
          >
            Reset
          </a>

        <?php endif; ?>

      </div>

    </div>

  </form>


  <!-- Activity -->
  <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">

    <!-- Section header -->
    <div class="flex flex-col gap-1 border-b border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">

      <div>

        <h2 class="text-lg font-bold font-alt">
          Activity history
        </h2>

        <p class="text-sm text-slate-600">

          Showing
          <?= $total ? (int)($offset + 1) : 0 ?>
          –
          <?= (int)min($offset + $limit, $total) ?>
          of
          <?= (int)$total ?>
          event(s).

        </p>

      </div>

      <div class="text-xs text-slate-500">
        Newest activity first
      </div>

    </div>


    <!-- Table -->
    <div class="overflow-x-auto">

      <table class="min-w-[860px] w-full text-sm">

        <thead class="bg-slate-50 text-slate-600">

          <tr>

            <th class="px-4 py-3 text-left font-semibold">
              Event
            </th>

            <th class="px-4 py-3 text-left font-semibold">
              Administrator
            </th>

            <th class="px-4 py-3 text-left font-semibold">
              Action
            </th>

            <th class="px-4 py-3 text-left font-semibold">
              Description
            </th>

            <th class="px-4 py-3 text-left font-semibold">
              Recorded
            </th>

          </tr>

        </thead>


        <tbody class="divide-y divide-slate-100">

          <?php if (!$logs): ?>

            <tr>

              <td
                  colspan="5"
                  class="px-4 py-10"
              >

                <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-center text-sm text-slate-700">

                  No audit events match the selected filters.

                </div>

              </td>

            </tr>

          <?php endif; ?>


          <?php foreach ($logs as $log): ?>

            <?php

            $recordedAt = (string)(
                $log['created_at'] ?? ''
            );

            $recordedLabel =
                $recordedAt !== ''
                && strtotime($recordedAt) !== false

                ? date(
                    'd M Y · H:i',
                    strtotime($recordedAt) + 10800
                )

                : '—';


            /*
             * Because actor_type is part of the audit schema,
             * do not assume every actor is an administrator.
             */

            $actorType = strtolower(
                trim((string)($log['actor_type'] ?? ''))
            );

            $actorId = (int)(
                $log['actor_id'] ?? 0
            );


            if (
                $actorType === 'admin'
                && (
                    !empty($log['full_name'])
                    || !empty($log['username'])
                )
            ) {

                $actorName =
                    (string)(
                        $log['full_name']
                        ?: $log['username']
                    );

                $actorSecondary =
                    (string)(
                        $log['username']
                        ?: 'Account #' . $actorId
                    );

            } else {

                $actorName =
                    $actorType !== ''
                    ? ucwords(
                        str_replace(
                            ['_', '-'],
                            ' ',
                            $actorType
                        )
                    )
                    : 'Unknown actor';

                $actorSecondary =
                    $actorId > 0
                    ? 'Account #' . $actorId
                    : '—';
            }

            ?>

            <tr class="align-top hover:bg-slate-50">


              <!-- Event -->
              <td class="whitespace-nowrap px-4 py-4 font-semibold text-slate-500">

                #<?= (int)$log['id'] ?>

              </td>


              <!-- Administrator -->
              <td class="px-4 py-4">

                <div class="font-semibold text-slate-900">

                  <?= e($actorName) ?>

                </div>

                <div class="mt-1 text-xs text-slate-500">

                  <?= e($actorSecondary) ?>

                </div>

              </td>


              <!-- Action -->
              <td class="whitespace-nowrap px-4 py-4">

                <?= $actionChip(
                    (string)($log['action'] ?? '')
                ) ?>

              </td>


              <!-- Description -->
              <td class="max-w-xl px-4 py-4 text-slate-700">

                <?= e(
                    (string)($log['details'] ?? '—')
                ) ?>

              </td>


              <!-- Recorded -->
              <td class="whitespace-nowrap px-4 py-4 text-xs text-slate-500">

                <?= e($recordedLabel) ?>

              </td>

            </tr>

          <?php endforeach; ?>

        </tbody>

      </table>

    </div>


    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>

      <div class="flex flex-col gap-3 border-t border-slate-200 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">

        <p class="text-sm text-slate-600">

          Page
          <span class="font-semibold">
            <?= (int)$page ?>
          </span>

          of

          <span class="font-semibold">
            <?= (int)$totalPages ?>
          </span>

        </p>


        <nav
            class="flex flex-wrap items-center gap-2"
            aria-label="Audit log pagination"
        >

          <!-- Previous -->
          <a
              href="<?= e($pageUrl($page - 1)) ?>"
              class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 <?= $page <= 1 ? 'pointer-events-none opacity-50' : '' ?>"
          >
            Previous
          </a>


          <?php

          $startPage = max(
              1,
              $page - 2
          );

          $endPage = min(
              $totalPages,
              $page + 2
          );

          for (
              $pageNumber = $startPage;
              $pageNumber <= $endPage;
              $pageNumber++
          ):

          ?>

            <a
                href="<?= e($pageUrl($pageNumber)) ?>"
                aria-current="<?= $pageNumber === $page ? 'page' : 'false' ?>"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-xl px-3 py-2 text-sm font-semibold <?= $pageNumber === $page ? 'bg-slate-900 text-white' : 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50' ?>"
            >

              <?= (int)$pageNumber ?>

            </a>

          <?php endfor; ?>


          <!-- Next -->
          <a
              href="<?= e($pageUrl($page + 1)) ?>"
              class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 <?= $page >= $totalPages ? 'pointer-events-none opacity-50' : '' ?>"
          >
            Next
          </a>

        </nav>

      </div>

    <?php endif; ?>

  </section>

</main>

</body>
</html>

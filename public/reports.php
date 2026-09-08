<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';

require_login();

$pdo = getDB();
$adminId = current_admin_id();
if ($adminId <= 0) {
    http_response_code(403);
    exit('Access denied.');
}

$timezone = new DateTimeZone('Africa/Kampala');
$now = new DateTimeImmutable('now', $timezone);
$currentYear = (int)$now->format('Y');
$currentMonth = $now->format('Y-m');
$currentMonthLabel = $now->format('F Y');

$yearValue = $_GET['year'] ?? $currentYear;
$year = is_scalar($yearValue) ? (int)$yearValue : $currentYear;
$year = max(2020, min($currentYear, $year));

$roomTypeValue = $_GET['room_type'] ?? '';
$roomTypeId = is_scalar($roomTypeValue) ? (int)$roomTypeValue : 0;
$roomTypeId = max(0, $roomTypeId);

$exportValue = $_GET['export'] ?? '';
$export = is_scalar($exportValue) ? strtolower(trim((string)$exportValue)) : '';
$export = in_array($export, ['csv', 'pdf'], true) ? $export : '';

$reportError = '';
$roomTypes = [];
$roomTypeName = '';
$paymentSummary = [
    'verified_income' => 0,
    'verified_payment_count' => 0,
    'pending_payment_count' => 0,
];
$kpi = [
    'active_tenants' => 0,
    'occupied_rooms' => 0,
];
$monthlyByMonth = [];
$outstandingRows = [];
$payments = [];

$rentExpression = "COALESCE(
    NULLIF(t.monthly_rent, 0),
    NULLIF(r.monthly_rent, 0),
    rt.default_monthly_rent,
    0
)";
$paymentMonthExpression = "COALESCE(NULLIF(p.payment_month, ''), strftime('%Y-%m', p.payment_date))";
$verifiedPaymentExpression = "LOWER(COALESCE(p.verification_status, 'done')) IN ('confirmed', 'done')";

try {
    $roomTypesStmt = $pdo->query(
        "SELECT id, name
         FROM room_types
         WHERE is_active = 1
         ORDER BY name"
    );
    $roomTypes = $roomTypesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $roomTypeNames = [];
    foreach ($roomTypes as $roomType) {
        $roomTypeNames[(int)$roomType['id']] = (string)$roomType['name'];
    }

    if ($roomTypeId > 0 && !isset($roomTypeNames[$roomTypeId])) {
        $roomTypeId = 0;
    }
    $roomTypeName = $roomTypeId > 0 ? ($roomTypeNames[$roomTypeId] ?? '') : '';
    $roomTypeFilter = $roomTypeId > 0 ? ' AND rt.id = :room_type_id' : '';

    $paymentSummaryStmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN {$verifiedPaymentExpression} THEN p.amount ELSE 0 END), 0) AS verified_income,
            COALESCE(SUM(CASE WHEN {$verifiedPaymentExpression} THEN 1 ELSE 0 END), 0) AS verified_payment_count,
            COALESCE(SUM(CASE WHEN LOWER(COALESCE(p.verification_status, 'done')) = 'pending' THEN 1 ELSE 0 END), 0) AS pending_payment_count
         FROM payments p
         JOIN tenants t ON t.id = p.tenant_id
         LEFT JOIN rooms r ON r.id = t.room_id
         LEFT JOIN room_types rt ON rt.id = r.room_type_id
         WHERE p.admin_id = :payment_admin_id
           AND t.admin_id = :tenant_admin_id
           AND substr({$paymentMonthExpression}, 1, 4) = :report_year
           {$roomTypeFilter}"
    );
    $paymentSummaryParams = [
        ':payment_admin_id' => $adminId,
        ':tenant_admin_id' => $adminId,
        ':report_year' => (string)$year,
    ];
    if ($roomTypeId > 0) {
        $paymentSummaryParams[':room_type_id'] = $roomTypeId;
    }
    $paymentSummaryStmt->execute($paymentSummaryParams);
    $paymentSummary = $paymentSummaryStmt->fetch(PDO::FETCH_ASSOC) ?: $paymentSummary;

    $activeTenantsStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM tenants t
         LEFT JOIN rooms r ON r.id = t.room_id
         LEFT JOIN room_types rt ON rt.id = r.room_type_id
         WHERE t.admin_id = :admin_id
           AND LOWER(COALESCE(t.status, 'active')) = 'active'
           AND t.exit_date IS NULL
           {$roomTypeFilter}"
    );
    $activeTenantParams = [':admin_id' => $adminId];
    if ($roomTypeId > 0) {
        $activeTenantParams[':room_type_id'] = $roomTypeId;
    }
    $activeTenantsStmt->execute($activeTenantParams);
    $kpi['active_tenants'] = (int)$activeTenantsStmt->fetchColumn();

    $occupiedRoomsStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM rooms r
         LEFT JOIN room_types rt ON rt.id = r.room_type_id
         WHERE r.admin_id = :admin_id
           AND r.status = 'occupied'
           {$roomTypeFilter}"
    );
    $occupiedRoomParams = [':admin_id' => $adminId];
    if ($roomTypeId > 0) {
        $occupiedRoomParams[':room_type_id'] = $roomTypeId;
    }
    $occupiedRoomsStmt->execute($occupiedRoomParams);
    $kpi['occupied_rooms'] = (int)$occupiedRoomsStmt->fetchColumn();

    $monthlyStmt = $pdo->prepare(
        "SELECT
            {$paymentMonthExpression} AS month_key,
            COALESCE(SUM(CASE WHEN {$verifiedPaymentExpression} THEN p.amount ELSE 0 END), 0) AS verified_total,
            COALESCE(SUM(CASE WHEN NOT ({$verifiedPaymentExpression}) THEN p.amount ELSE 0 END), 0) AS pending_total,
            COUNT(*) AS payment_count
         FROM payments p
         JOIN tenants t ON t.id = p.tenant_id
         LEFT JOIN rooms r ON r.id = t.room_id
         LEFT JOIN room_types rt ON rt.id = r.room_type_id
         WHERE p.admin_id = :payment_admin_id
           AND t.admin_id = :tenant_admin_id
           AND substr({$paymentMonthExpression}, 1, 4) = :report_year
           {$roomTypeFilter}
         GROUP BY month_key
         ORDER BY month_key"
    );
    $monthlyParams = [
        ':payment_admin_id' => $adminId,
        ':tenant_admin_id' => $adminId,
        ':report_year' => (string)$year,
    ];
    if ($roomTypeId > 0) {
        $monthlyParams[':room_type_id'] = $roomTypeId;
    }
    $monthlyStmt->execute($monthlyParams);

    foreach ($monthlyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $monthRow) {
        $monthKey = (string)($monthRow['month_key'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthKey)) {
            continue;
        }

        $monthlyByMonth[$monthKey] = [
            'verified_total' => (float)($monthRow['verified_total'] ?? 0),
            'pending_total' => (float)($monthRow['pending_total'] ?? 0),
            'payment_count' => (int)($monthRow['payment_count'] ?? 0),
        ];
    }

    $outstandingStmt = $pdo->prepare(
        "SELECT
            t.id AS tenant_id,
            t.full_name,
            r.room_number,
            {$rentExpression} AS rent,
            COALESCE(SUM(p.amount), 0) AS paid,
            MAX(0, {$rentExpression} - COALESCE(SUM(p.amount), 0)) AS balance
         FROM tenants t
         LEFT JOIN rooms r ON r.id = t.room_id
         LEFT JOIN room_types rt ON rt.id = r.room_type_id
         LEFT JOIN payments p
           ON p.tenant_id = t.id
          AND p.admin_id = :payment_admin_id
          AND {$paymentMonthExpression} = :current_month
          AND {$verifiedPaymentExpression}
         WHERE t.admin_id = :tenant_admin_id
           AND LOWER(COALESCE(t.status, 'active')) = 'active'
           AND t.exit_date IS NULL
           {$roomTypeFilter}
         GROUP BY
            t.id,
            t.full_name,
            r.room_number,
            t.monthly_rent,
            r.monthly_rent,
            rt.default_monthly_rent
         HAVING MAX(0, {$rentExpression} - COALESCE(SUM(p.amount), 0)) > 0
         ORDER BY balance DESC, t.full_name ASC"
    );
    $outstandingParams = [
        ':payment_admin_id' => $adminId,
        ':current_month' => $currentMonth,
        ':tenant_admin_id' => $adminId,
    ];
    if ($roomTypeId > 0) {
        $outstandingParams[':room_type_id'] = $roomTypeId;
    }
    $outstandingStmt->execute($outstandingParams);
    $outstandingRows = $outstandingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $paymentLedgerStmt = $pdo->prepare(
        "SELECT
            p.id,
            p.payment_date,
            p.payment_month,
            p.amount,
            p.method,
            COALESCE(p.verification_status, 'done') AS verification_status,
            t.full_name,
            r.room_number,
            rt.name AS room_type
         FROM payments p
         JOIN tenants t ON t.id = p.tenant_id
         LEFT JOIN rooms r ON r.id = t.room_id
         LEFT JOIN room_types rt ON rt.id = r.room_type_id
         WHERE p.admin_id = :payment_admin_id
           AND t.admin_id = :tenant_admin_id
           AND substr({$paymentMonthExpression}, 1, 4) = :report_year
           {$roomTypeFilter}
         ORDER BY p.payment_date DESC, p.id DESC"
    );
    $paymentLedgerParams = [
        ':payment_admin_id' => $adminId,
        ':tenant_admin_id' => $adminId,
        ':report_year' => (string)$year,
    ];
    if ($roomTypeId > 0) {
        $paymentLedgerParams[':room_type_id'] = $roomTypeId;
    }
    $paymentLedgerStmt->execute($paymentLedgerParams);
    $payments = $paymentLedgerStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    error_log('Reports page failed: ' . $e->getMessage());
    $reportError = 'Unable to load the report data right now. Please try again.';
    $roomTypes = [];
    $roomTypeName = '';
    $paymentSummary = [
        'verified_income' => 0,
        'verified_payment_count' => 0,
        'pending_payment_count' => 0,
    ];
    $kpi = [
        'active_tenants' => 0,
        'occupied_rooms' => 0,
    ];
    $monthlyByMonth = [];
    $outstandingRows = [];
    $payments = [];
}

$scopeLabel = $roomTypeName !== ''
    ? $year . ' · ' . $roomTypeName
    : $year . ' · All room types';
$currentMonthScopeLabel = $roomTypeName !== ''
    ? $currentMonthLabel . ' · ' . $roomTypeName
    : $currentMonthLabel . ' · All room types';
$hasNonDefaultFilters = $year !== $currentYear || $roomTypeId > 0;

$buildReportUrl = static function (array $overrides = []) use ($year, $roomTypeId): string {
    $query = [
        'year' => $year,
        'room_type' => $roomTypeId > 0 ? $roomTypeId : '',
    ];

    foreach ($overrides as $key => $value) {
        $query[$key] = $value;
    }

    $query = array_filter(
        $query,
        static fn($value): bool => $value !== '' && $value !== null
    );

    return 'reports.php' . ($query ? '?' . http_build_query($query) : '');
};

$formatUgx = static fn(float|int|string $value): string => 'UGX ' . number_format((float)$value, 0, '.', ',');
$formatReportDate = static function (?string $value) use ($timezone): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone($timezone)
            ->format('d M Y');
    } catch (Throwable $ignored) {
        return '—';
    }
};
$formatReportMonth = static function (?string $value): string {
    $value = trim((string)$value);
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
        return '—';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value . '-01');
    return $date ? $date->format('M Y') : '—';
};
$verificationLabel = static function (string $value): string {
    return match (strtolower($value)) {
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'done' => 'Done',
        default => ucfirst($value !== '' ? $value : 'Unknown'),
    };
};
$verificationTone = static function (string $value): string {
    return match (strtolower($value)) {
        'pending' => 'amber',
        'confirmed' => 'blue',
        'done' => 'green',
        default => 'slate',
    };
};
$reportChip = static function (string $label, string $tone = 'slate'): string {
    $toneClasses = [
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'red' => 'bg-red-50 text-red-700 ring-red-200',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-200',
    ];
    $classes = $toneClasses[$tone] ?? $toneClasses['slate'];

    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ' . $classes . '">' . e($label) . '</span>';
};

$verifiedIncome = (float)($paymentSummary['verified_income'] ?? 0);
$verifiedPaymentCount = (int)($paymentSummary['verified_payment_count'] ?? 0);
$pendingPaymentCount = (int)($paymentSummary['pending_payment_count'] ?? 0);
$outstandingTotal = array_sum(array_map(
    static fn(array $row): float => max(0, (float)($row['balance'] ?? 0)),
    $outstandingRows
));
$monthlyRows = [];
$maximumMonthlyTotal = 0.0;
for ($monthNumber = 1; $monthNumber <= 12; $monthNumber++) {
    $monthKey = sprintf('%04d-%02d', $year, $monthNumber);
    $monthData = $monthlyByMonth[$monthKey] ?? [
        'verified_total' => 0,
        'pending_total' => 0,
        'payment_count' => 0,
    ];
    $maximumMonthlyTotal = max($maximumMonthlyTotal, (float)$monthData['verified_total']);
    $monthlyRows[] = [
        'month_key' => $monthKey,
        ...$monthData,
    ];
}

if ($export !== '' && $reportError === '') {
    try {
        logAudit($adminId, 'REPORT_EXPORTED', strtoupper($export) . " payments report exported for {$scopeLabel}.");

        if ($export === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="payments_' . $year . '.csv"');
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                throw new RuntimeException('Unable to open export stream.');
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Payment date', 'Payment month', 'Tenant', 'Room', 'Room type', 'Amount', 'Method', 'Verification']);
            foreach ($payments as $payment) {
                fputcsv($out, [
                    $formatReportDate((string)($payment['payment_date'] ?? '')),
                    $formatReportMonth((string)($payment['payment_month'] ?? '')),
                    (string)($payment['full_name'] ?? ''),
                    (string)($payment['room_number'] ?? '—'),
                    (string)($payment['room_type'] ?? '—'),
                    (string)($payment['amount'] ?? 0),
                    (string)($payment['method'] ?? 'cash'),
                    $verificationLabel((string)($payment['verification_status'] ?? 'done')),
                ]);
            }
            fclose($out);
            exit;
        }

        $tcpdfPath = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
        if (!is_file($tcpdfPath)) {
            throw new RuntimeException('PDF export is not configured.');
        }
        require_once $tcpdfPath;

        $pdf = new TCPDF();
        $pdf->SetCreator('Urbahan Tenant Portal');
        $pdf->SetTitle('Payments report ' . $scopeLabel);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 9);
        $pdfHtml = '<h2>Payments report — ' . e($scopeLabel) . '</h2>';
        $pdfHtml .= '<p>Verified payments are marked Done or Confirmed. Pending payments remain visible but are not included in verified income.</p>';
        $pdfHtml .= '<table border="1" cellpadding="5"><thead><tr><th>Date</th><th>Month</th><th>Tenant</th><th>Room</th><th>Amount</th><th>Verification</th></tr></thead><tbody>';
        foreach ($payments as $payment) {
            $pdfHtml .= '<tr>'
                . '<td>' . e($formatReportDate((string)($payment['payment_date'] ?? ''))) . '</td>'
                . '<td>' . e($formatReportMonth((string)($payment['payment_month'] ?? ''))) . '</td>'
                . '<td>' . e((string)($payment['full_name'] ?? '')) . '</td>'
                . '<td>' . e((string)($payment['room_number'] ?? '—')) . '</td>'
                . '<td>UGX ' . e(number_format((float)($payment['amount'] ?? 0), 0, '.', ',')) . '</td>'
                . '<td>' . e($verificationLabel((string)($payment['verification_status'] ?? 'done'))) . '</td>'
                . '</tr>';
        }
        $pdfHtml .= '</tbody></table>';
        $pdf->writeHTML($pdfHtml, true, false, true, false, '');
        $pdf->Output('payments_' . $year . '.pdf', 'D');
        exit;
    } catch (Throwable $e) {
        error_log('Report export failed: ' . $e->getMessage());
        $reportError = 'The report could not be exported. Please try again.';
    }
}

$active = 'reports';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reports · Urbahan Tenant Portal</title>
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<?php require __DIR__ . '/partials/navbar.php'; ?>

<main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
  <header class="mb-6 flex flex-col gap-4 border-b border-slate-200 pb-6 lg:flex-row lg:items-end lg:justify-between">
    <div>
      <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Operations / Reporting</p>
      <h1 class="mt-2 font-alt text-3xl font-bold tracking-tight">Reports</h1>
      <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
        Verified collections, rent exceptions, and payment activity for <?= e($scopeLabel) ?>.
      </p>
    </div>

    <div class="flex flex-wrap gap-2">
      <?php if ($reportError === ''): ?>
        <a href="<?= e($buildReportUrl(['export' => 'csv'])) ?>"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 transition hover:bg-slate-50 active:-translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
          Download CSV
        </a>
        <a href="<?= e($buildReportUrl(['export' => 'pdf'])) ?>"
           class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 active:-translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
          Download PDF
        </a>
      <?php else: ?>
        <span class="inline-flex min-h-11 items-center rounded-xl border border-slate-200 bg-slate-100 px-4 py-2 text-sm font-semibold text-slate-500" aria-disabled="true">
          Exports unavailable
        </span>
      <?php endif; ?>
    </div>
  </header>

  <?php if ($reportError): ?>
    <div class="mb-6 flex flex-col gap-3 border-l-4 border-red-500 bg-red-50 px-4 py-4 text-sm text-red-900 sm:flex-row sm:items-center sm:justify-between" role="alert" aria-live="polite">
      <div>
        <p class="font-semibold"><?= e($reportError) ?></p>
        <p class="mt-1 text-red-800">Your report filters were kept, but no partial totals are shown.</p>
      </div>
      <a href="<?= e($buildReportUrl()) ?>" class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-red-200 bg-white px-4 py-2 text-sm font-semibold text-red-900 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-red-700">Try again</a>
    </div>
  <?php endif; ?>

  <section class="mb-8 border-b border-slate-200 pb-6" aria-labelledby="report-scope-title">
    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
      <div>
        <h2 id="report-scope-title" class="font-alt text-lg font-bold">Report scope</h2>
        <p class="mt-1 text-sm text-slate-600">The selected scope applies to every total, exception list, ledger row, and export.</p>
      </div>
      <?php if ($hasNonDefaultFilters): ?>
        <a href="reports.php" class="text-sm font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-900">Clear filters</a>
      <?php endif; ?>
    </div>

    <form method="get" class="grid grid-cols-1 gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:items-end">
      <div>
        <label for="report-year" class="mb-1.5 block text-xs font-semibold text-slate-600">Billing year</label>
        <select id="report-year" name="year" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200">
          <?php for ($optionYear = $currentYear; $optionYear >= 2020; $optionYear--): ?>
            <option value="<?= $optionYear ?>" <?= $optionYear === $year ? 'selected' : '' ?>><?= $optionYear ?></option>
          <?php endfor; ?>
        </select>
      </div>

      <div>
        <label for="report-room-type" class="mb-1.5 block text-xs font-semibold text-slate-600">Room type</label>
        <select id="report-room-type" name="room_type" class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus-visible:border-slate-500 focus-visible:ring-2 focus-visible:ring-slate-200">
          <option value="">All room types</option>
          <?php foreach ($roomTypes as $roomType): ?>
            <option value="<?= (int)$roomType['id'] ?>" <?= $roomTypeId === (int)$roomType['id'] ? 'selected' : '' ?>><?= e($roomType['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-slate-900 px-5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 active:-translate-y-px focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900">
        Apply scope
      </button>
    </form>
  </section>

  <section aria-labelledby="financial-snapshot-title">
    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Financial snapshot</p>
        <h2 id="financial-snapshot-title" class="mt-1 font-alt text-xl font-bold">Verified collections</h2>
      </div>
      <p class="text-xs text-slate-500">Done and confirmed records only · <?= e($scopeLabel) ?></p>
    </div>

    <div class="grid grid-cols-2 gap-px overflow-hidden border border-slate-200 bg-slate-200 lg:grid-cols-4">
      <div class="bg-white p-4 sm:p-5">
        <p class="text-xs font-semibold text-slate-500">Verified collections</p>
        <p class="mt-2 font-alt text-2xl font-bold tabular-nums tracking-tight text-slate-950 sm:text-3xl"><?= e($formatUgx($verifiedIncome)) ?></p>
      </div>
      <div class="bg-white p-4 sm:p-5">
        <p class="text-xs font-semibold text-slate-500">Verified payments</p>
        <p class="mt-2 font-alt text-2xl font-bold tabular-nums text-slate-950"><?= number_format($verifiedPaymentCount) ?></p>
        <?php if ($pendingPaymentCount > 0): ?><p class="mt-1 text-xs text-amber-700"><?= number_format($pendingPaymentCount) ?> pending review</p><?php endif; ?>
      </div>
      <div class="bg-white p-4 sm:p-5">
        <p class="text-xs font-semibold text-slate-500">Active tenants</p>
        <p class="mt-2 font-alt text-2xl font-bold tabular-nums text-slate-950"><?= number_format((int)$kpi['active_tenants']) ?></p>
      </div>
      <div class="bg-white p-4 sm:p-5">
        <p class="text-xs font-semibold text-slate-500">Occupied rooms</p>
        <p class="mt-2 font-alt text-2xl font-bold tabular-nums text-slate-950"><?= number_format((int)$kpi['occupied_rooms']) ?></p>
      </div>
    </div>
  </section>

  <section class="mt-10 border-t border-slate-200 pt-6" aria-labelledby="monthly-activity-title">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h2 id="monthly-activity-title" class="font-alt text-xl font-bold">Monthly payment activity</h2>
        <p class="mt-1 text-sm text-slate-600">Verified collections by billing month. Pending submissions are shown separately.</p>
      </div>
      <p class="text-xs text-slate-500">All 12 months · UGX</p>
    </div>

    <div class="mt-4 overflow-x-auto border-y border-slate-200">
      <table class="min-w-full text-sm">
        <caption class="sr-only">Monthly verified and pending payment activity for <?= e($scopeLabel) ?></caption>
        <thead class="bg-slate-100 text-slate-600">
          <tr>
            <th scope="col" class="px-4 py-3 text-left font-semibold">Billing month</th>
            <th scope="col" class="px-4 py-3 text-left font-semibold">Verified total</th>
            <th scope="col" class="px-4 py-3 text-left font-semibold">Submitted pending</th>
            <th scope="col" class="px-4 py-3 text-left font-semibold">Records</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <?php foreach ($monthlyRows as $monthRow): ?>
            <?php
              $verifiedTotal = (float)$monthRow['verified_total'];
              $pendingTotal = (float)$monthRow['pending_total'];
              $barWidth = $maximumMonthlyTotal > 0 ? min(100, ($verifiedTotal / $maximumMonthlyTotal) * 100) : 0;
            ?>
            <tr>
              <th scope="row" class="whitespace-nowrap px-4 py-3 text-left font-semibold text-slate-800"><?= e($formatReportMonth($monthRow['month_key'])) ?></th>
              <td class="min-w-56 px-4 py-3">
                <div class="flex items-center justify-between gap-3 tabular-nums">
                  <span class="font-semibold text-slate-900"><?= e($formatUgx($verifiedTotal)) ?></span>
                  <span class="text-xs text-slate-500"><?= $verifiedTotal > 0 ? e(number_format($barWidth, 0) . '% of peak') : '—' ?></span>
                </div>
                <div class="mt-2 h-1.5 w-full rounded-full bg-slate-100" aria-hidden="true"><span class="block h-1.5 rounded-full bg-slate-900" style="width: <?= e(number_format($barWidth, 2, '.', '')) ?>%"></span></div>
              </td>
              <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600"><?= e($formatUgx($pendingTotal)) ?></td>
              <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-600"><?= number_format((int)$monthRow['payment_count']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="mt-10 border-t border-slate-200 pt-6" aria-labelledby="outstanding-title">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-red-700">Collection exceptions</p>
        <h2 id="outstanding-title" class="mt-1 font-alt text-xl font-bold">Outstanding rent</h2>
        <p class="mt-1 text-sm text-slate-600">Active tenants with a positive balance for <?= e($currentMonthScopeLabel) ?>. Only confirmed and done payments reduce the balance.</p>
      </div>
      <div class="mt-2 text-left sm:mt-0 sm:text-right">
        <p class="text-xs text-slate-500"><?= number_format(count($outstandingRows)) ?> tenant(s)</p>
        <p class="mt-1 font-alt text-lg font-bold tabular-nums text-red-700"><?= e($formatUgx($outstandingTotal)) ?></p>
      </div>
    </div>

    <?php if ($outstandingRows): ?>
      <div class="mt-4 hidden overflow-x-auto border-y border-slate-200 md:block">
        <table class="min-w-full text-sm">
          <caption class="sr-only">Outstanding rent for <?= e($currentMonthScopeLabel) ?></caption>
          <thead class="bg-slate-100 text-slate-600">
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Tenant</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Room</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Rent</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Verified paid</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Balance</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($outstandingRows as $outstanding): ?>
              <?php
                $rent = max(0, (float)($outstanding['rent'] ?? 0));
                $paid = max(0, (float)($outstanding['paid'] ?? 0));
                $balance = max(0, (float)($outstanding['balance'] ?? ($rent - $paid)));
                $rentStatus = $paid > 0 ? 'Partial' : 'Unpaid';
              ?>
              <tr>
                <th scope="row" class="px-4 py-3 text-left font-semibold text-slate-900"><?= e($outstanding['full_name'] ?? 'Unknown tenant') ?></th>
                <td class="px-4 py-3 text-slate-700"><?= e($outstanding['room_number'] ?? '—') ?></td>
                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700"><?= e($formatUgx($rent)) ?></td>
                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-slate-700"><?= e($formatUgx($paid)) ?></td>
                <td class="whitespace-nowrap px-4 py-3 font-bold tabular-nums text-red-700"><?= e($formatUgx($balance)) ?></td>
                <td class="px-4 py-3"><?= $reportChip($rentStatus, $rentStatus === 'Partial' ? 'amber' : 'red') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <ul class="mt-4 divide-y divide-slate-200 border-y border-slate-200 md:hidden" aria-label="Outstanding rent tenants">
        <?php foreach ($outstandingRows as $outstanding): ?>
          <?php
            $rent = max(0, (float)($outstanding['rent'] ?? 0));
            $paid = max(0, (float)($outstanding['paid'] ?? 0));
            $balance = max(0, (float)($outstanding['balance'] ?? ($rent - $paid)));
            $rentStatus = $paid > 0 ? 'Partial' : 'Unpaid';
          ?>
          <li class="py-4 first:pt-3 last:pb-3">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="truncate font-semibold text-slate-900"><?= e($outstanding['full_name'] ?? 'Unknown tenant') ?></p>
                <p class="mt-1 text-xs text-slate-500">Room <?= e($outstanding['room_number'] ?? '—') ?> · <?= e($currentMonthLabel) ?></p>
              </div>
              <?= $reportChip($rentStatus, $rentStatus === 'Partial' ? 'amber' : 'red') ?>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-3 text-xs">
              <div><p class="text-slate-500">Rent</p><p class="mt-1 font-semibold tabular-nums text-slate-800"><?= e($formatUgx($rent)) ?></p></div>
              <div><p class="text-slate-500">Paid</p><p class="mt-1 font-semibold tabular-nums text-slate-800"><?= e($formatUgx($paid)) ?></p></div>
              <div><p class="text-slate-500">Balance</p><p class="mt-1 font-bold tabular-nums text-red-700"><?= e($formatUgx($balance)) ?></p></div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="mt-4 border-y border-dashed border-slate-300 py-8 text-sm text-slate-700">
        <p class="font-semibold text-slate-900">No outstanding rent for <?= e($currentMonthScopeLabel) ?>.</p>
        <p class="mt-1">The collection queue is clear for this scope.</p>
      </div>
    <?php endif; ?>
  </section>

  <section class="mt-10 border-t border-slate-200 pt-6" aria-labelledby="payment-history-title">
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h2 id="payment-history-title" class="font-alt text-xl font-bold">Payment ledger</h2>
        <p class="mt-1 text-sm text-slate-600">Every payment allocated to <?= e($scopeLabel) ?>, including its verification state.</p>
      </div>
      <p class="text-xs text-slate-500"><?= number_format(count($payments)) ?> record(s)</p>
    </div>

    <?php if ($payments): ?>
      <div class="mt-4 hidden overflow-x-auto border-y border-slate-200 md:block">
        <table class="min-w-full text-sm">
          <caption class="sr-only">Payment ledger for <?= e($scopeLabel) ?></caption>
          <thead class="bg-slate-100 text-slate-600">
            <tr>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Payment date</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Tenant</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Room</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Type</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Billing month</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Amount</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Method</th>
              <th scope="col" class="px-4 py-3 text-left font-semibold">Verification</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <?php foreach ($payments as $payment): ?>
              <?php
                $verificationStatus = strtolower((string)($payment['verification_status'] ?? 'done'));
                $method = ucfirst(strtolower((string)($payment['method'] ?? 'cash')));
              ?>
              <tr>
                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($formatReportDate((string)($payment['payment_date'] ?? ''))) ?></td>
                <th scope="row" class="px-4 py-3 text-left font-semibold text-slate-900"><?= e($payment['full_name'] ?? 'Unknown tenant') ?></th>
                <td class="px-4 py-3 text-slate-700"><?= e($payment['room_number'] ?? '—') ?></td>
                <td class="px-4 py-3 text-slate-700"><?= e($payment['room_type'] ?? '—') ?></td>
                <td class="whitespace-nowrap px-4 py-3 text-slate-700"><?= e($formatReportMonth((string)($payment['payment_month'] ?? ''))) ?></td>
                <td class="whitespace-nowrap px-4 py-3 font-semibold tabular-nums text-slate-900"><?= e($formatUgx($payment['amount'] ?? 0)) ?></td>
                <td class="px-4 py-3 text-slate-700"><?= e($method) ?></td>
                <td class="px-4 py-3"><?= $reportChip($verificationLabel($verificationStatus), $verificationTone($verificationStatus)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <ul class="mt-4 divide-y divide-slate-200 border-y border-slate-200 md:hidden" aria-label="Payment ledger records">
        <?php foreach ($payments as $payment): ?>
          <?php
            $verificationStatus = strtolower((string)($payment['verification_status'] ?? 'done'));
            $method = ucfirst(strtolower((string)($payment['method'] ?? 'cash')));
          ?>
          <li class="py-4 first:pt-3 last:pb-3">
            <div class="flex items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="truncate font-semibold text-slate-900"><?= e($payment['full_name'] ?? 'Unknown tenant') ?></p>
                <p class="mt-1 text-xs text-slate-500">Room <?= e($payment['room_number'] ?? '—') ?> · <?= e($formatReportMonth((string)($payment['payment_month'] ?? ''))) ?></p>
              </div>
              <p class="shrink-0 font-alt text-base font-bold tabular-nums text-slate-950"><?= e($formatUgx($payment['amount'] ?? 0)) ?></p>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2 text-xs text-slate-600">
              <span><?= e($formatReportDate((string)($payment['payment_date'] ?? ''))) ?></span>
              <span aria-hidden="true">·</span>
              <span><?= e($method) ?></span>
              <span><?= $reportChip($verificationLabel($verificationStatus), $verificationTone($verificationStatus)) ?></span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <div class="mt-4 border-y border-dashed border-slate-300 py-8 text-sm text-slate-700">
        <p class="font-semibold text-slate-900">No payment records for <?= e($scopeLabel) ?>.</p>
        <p class="mt-1">Try another billing year or room type.</p>
        <?php if ($hasNonDefaultFilters): ?><a href="reports.php" class="mt-3 inline-flex min-h-11 items-center font-semibold text-slate-900 underline decoration-slate-300 underline-offset-4 hover:decoration-slate-900">Clear filters</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </section>
</main>

</body>
</html>

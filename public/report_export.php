<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../services/ReportService.php';

require_login();
require_capability('reports.view');

$pdo = getDB();
$businessId = current_business_id();
if ($businessId <= 0) {
    http_response_code(403);
    exit('Access denied.');
}

$reports = new ReportService($pdo);
$type = (string)($_GET['type'] ?? 'summary');
$period = $reports->resolvePeriod(
    isset($_GET['from']) ? (string)$_GET['from'] : null,
    isset($_GET['to']) ? (string)$_GET['to'] : null
);

$filters = [
    'from' => $period['from'],
    'to' => $period['to'],
    'property' => max(0, (int)($_GET['property'] ?? 0)),
    'status' => (string)($_GET['status'] ?? ''),
    'method' => (string)($_GET['method'] ?? ''),
    'search' => trim((string)($_GET['search'] ?? '')),
];

$propertyId = (int)$filters['property'];

$rows = [];
$headers = [];

switch ($type) {
    case 'arrears':
        $arrears = $reports->arrears($businessId, $propertyId);
        $headers = ['Tenant', 'Phone', 'Property', 'Room', 'Owed since', 'Expected', 'Paid', 'Balance', 'Days overdue'];
        foreach ($arrears['rows'] as $row) {
            $rows[] = [
                $row['full_name'],
                $row['phone'],
                $row['property_name'] !== '' ? $row['property_name'] : 'Unassigned',
                $row['room_number'],
                $row['owed_since_label'],
                $row['expected'],
                $row['verified_paid'],
                $row['balance'],
                $row['days_overdue'],
            ];
        }
        break;

    case 'properties':
        $headers = ['Property', 'Address', 'Rooms', 'Occupied', 'Vacant', 'Occupancy %', 'Expected', 'Collected', 'Outstanding'];
        foreach ($reports->propertyPerformance($businessId, $period) as $row) {
            $rows[] = [
                $row['name'],
                $row['address'],
                $row['rooms_total'],
                $row['rooms_occupied'],
                $row['rooms_vacant'],
                number_format((float)$row['occupancy_rate'] * 100, 1),
                $row['expected'],
                $row['collected'],
                $row['outstanding'],
            ];
        }
        break;

    case 'ledger':
        $headers = ['Receipt', 'Tenant', 'Property', 'Room', 'Period', 'Paid on', 'Method', 'Status', 'Amount', 'Recorded by'];
        foreach ($reports->ledgerForExport($businessId, $filters) as $row) {
            $rows[] = [
                $row['receipt_no'],
                $row['tenant_name'],
                $row['property_name'] ?? 'Unassigned',
                $row['room_number'] ?? '',
                $row['payment_month'],
                $row['payment_date'],
                $row['method'],
                $row['verification_status'],
                $row['amount'],
                $row['recorded_by'] ?? '',
            ];
        }
        break;

    case 'summary':
    default:
        $type = 'summary';
        $overview = $reports->overview($businessId, $period, $propertyId);
        $headers = ['Measure', 'Value'];
        foreach ([
            'Period from' => $period['from'],
            'Period to' => $period['to'],
            'Expected for period' => $overview['expected'],
            'Confirmed collected' => $overview['collected'],
            'Pending verification' => $overview['pending'],
            'Reversed' => $overview['reversed'],
            'Outstanding' => $overview['outstanding'],
            'Overdue' => $overview['overdue'],
            'Overdue tenants' => $overview['overdue_tenants'],
            'Rooms total' => $overview['rooms_total'],
            'Rooms occupied' => $overview['rooms_occupied'],
            'Occupancy %' => number_format((float)$overview['occupancy_rate'] * 100, 1),
            'Collection rate %' => number_format((float)$overview['collection_rate'] * 100, 1),
        ] as $label => $value) {
            $rows[] = [$label, $value];
        }
        break;
}

$filename = sprintf(
    'urbahan-%s-%s-to-%s.csv',
    $type,
    str_replace('-', '', $period['from']),
    str_replace('-', '', $period['to'])
);

logAudit(
    (int)($_SESSION['admin_id'] ?? 0),
    'REPORT_EXPORTED',
    sprintf('%s report exported (%s, %d rows).', ucfirst($type), $period['label'], count($rows)),
    ['target_type' => 'report', 'owner_admin_id' => $businessId]
);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// Excel needs a BOM to read UTF-8 reliably.
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $headers);

/*
 * Spreadsheet formula injection: a tenant name starting with = + - @ (or a
 * control character) would be executed as a formula by Excel or Sheets.
 * Such cells are prefixed with an apostrophe so they stay text.
 */
$csvCell = static function ($value): string {
    $text = (string)$value;

    if ($text !== '' && strpbrk($text[0], "=+-@\t\r") !== false) {
        return "'" . $text;
    }

    return $text;
};

foreach ($rows as $row) {
    fputcsv($out, array_map($csvCell, $row));
}

fclose($out);
exit;

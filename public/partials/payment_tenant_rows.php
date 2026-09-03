<?php
declare(strict_types=1);

if (!function_exists('paymentChip')) {
    function paymentChip(string $label, string $tone): string
    {
        $map = [
            'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
            'red'   => 'bg-red-50 text-red-700 ring-red-200',
            'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            'amber' => 'bg-amber-50 text-amber-700 ring-amber-200',
            'blue'  => 'bg-sky-50 text-sky-700 ring-sky-200',
        ];
        $classes = $map[$tone] ?? $map['slate'];

        return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ' . $classes . '">' . e($label) . '</span>';
    }
}

$tenants = $tenants ?? [];
$paymentFormData = $paymentFormData ?? [];
$paymentActionQuery = $paymentActionQuery ?? [];
$paymentAction = 'payments.php';
if ($paymentActionQuery) {
    $paymentAction .= '?' . http_build_query($paymentActionQuery);
}

$today = date('Y-m-d');
$postedTenantId = (int)($paymentFormData['tenant_id'] ?? 0);
?>

<?php if (!$tenants): ?>
  <tr>
    <td colspan="6" class="px-4 py-6">
      <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-4 text-sm text-slate-700">
        No active tenants match the selected filters.
      </div>
    </td>
  </tr>
<?php endif; ?>

<?php foreach ($tenants as $tenant): ?>
  <?php
    $tenantId = (int)($tenant['id'] ?? 0);
    $tenantName = (string)($tenant['full_name'] ?? '');
    $dueRaw = (string)($tenant['rent_due_date'] ?? '');
    $dueTimestamp = $dueRaw !== '' ? strtotime($dueRaw) : false;
    $isOverdue = $dueTimestamp !== false && $dueTimestamp < strtotime($today);

    $rent = (float)($tenant['monthly_rent_effective'] ?? 0);
    $paid = (float)($tenant['paid_this_month'] ?? 0);
    $outstanding = (float)($tenant['outstanding_balance'] ?? 0);

    if ($rent <= 0) {
        $statusChip = paymentChip('Rent missing', 'red');
    } elseif ($outstanding <= 0.00001) {
        $statusChip = paymentChip('Paid', 'green');
    } elseif ($paid > 0) {
        $statusChip = paymentChip('Partial', 'amber');
    } else {
        $statusChip = paymentChip('Unpaid', 'red');
    }

    $isPostedTenant = $postedTenantId === $tenantId;
    $amountValue = $isPostedTenant ? (string)($paymentFormData['amount'] ?? '') : '';
    $dateValue = $isPostedTenant
        ? (string)($paymentFormData['payment_date'] ?? $today)
        : $today;
    $methodValue = $isPostedTenant
        ? (string)($paymentFormData['method'] ?? 'cash')
        : 'cash';
    $noteValue = $isPostedTenant ? (string)($paymentFormData['note'] ?? '') : '';
  ?>
  <tr class="align-top hover:bg-slate-50">
    <td class="px-4 py-3">
      <div class="font-semibold text-slate-900"><?= e($tenantName) ?></div>
      <div class="mt-1"><?= $statusChip ?></div>
    </td>

    <td class="px-4 py-3 text-slate-700">
      <?= e($tenant['room_number'] ?? '-') ?>
      <?php if (!empty($tenant['room_type'])): ?>
        <div class="mt-1 text-xs text-slate-500"><?= e($tenant['room_type']) ?></div>
      <?php endif; ?>
    </td>

    <td class="px-4 py-3">
      <div class="<?= $isOverdue ? 'font-semibold text-red-700' : 'text-slate-700' ?>">
        <?= e($dueTimestamp !== false ? date('d M Y', $dueTimestamp) : '—') ?>
      </div>
      <?php if ($isOverdue): ?>
        <div class="mt-1 text-xs text-red-600">Overdue</div>
      <?php endif; ?>
    </td>

    <td class="px-4 py-3 whitespace-nowrap font-semibold text-slate-700">
      UGX <?= e(number_format($rent, 0)) ?>
    </td>

    <td class="px-4 py-3 whitespace-nowrap">
      <div class="font-semibold <?= $outstanding > 0 ? 'text-slate-900' : 'text-emerald-700' ?>">
        UGX <?= e(number_format($outstanding, 0)) ?>
      </div>
      <div class="mt-1 text-xs text-slate-500">Paid: UGX <?= e(number_format($paid, 0)) ?></div>
    </td>

    <td class="px-4 py-3">
      <form method="post" action="<?= e($paymentAction) ?>" class="flex min-w-[520px] flex-col gap-2 lg:min-w-0" data-payment-form>
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="tenant_id" value="<?= $tenantId ?>">

        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-5">
          <div>
            <label class="sr-only" for="payment_amount_<?= $tenantId ?>">Amount for <?= e($tenantName) ?></label>
            <input
              id="payment_amount_<?= $tenantId ?>"
              type="number"
              name="amount"
              min="0.01"
              step="0.01"
              inputmode="decimal"
              required
              value="<?= e($amountValue) ?>"
              placeholder="Amount"
              class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
            >
          </div>

          <div>
            <label class="sr-only" for="payment_date_<?= $tenantId ?>">Payment date for <?= e($tenantName) ?></label>
            <input
              id="payment_date_<?= $tenantId ?>"
              type="date"
              name="payment_date"
              value="<?= e($dateValue) ?>"
              required
              class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
            >
          </div>

          <div>
            <label class="sr-only" for="payment_method_<?= $tenantId ?>">Payment method for <?= e($tenantName) ?></label>
            <select
              id="payment_method_<?= $tenantId ?>"
              name="method"
              class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
            >
              <option value="cash" <?= $methodValue === 'cash' ? 'selected' : '' ?>>Cash</option>
              <option value="mobile" <?= $methodValue === 'mobile' ? 'selected' : '' ?>>Mobile</option>
              <option value="bank" <?= $methodValue === 'bank' ? 'selected' : '' ?>>Bank</option>
            </select>
          </div>

          <div class="sm:col-span-2 xl:col-span-2">
            <label class="sr-only" for="payment_note_<?= $tenantId ?>">Optional payment note for <?= e($tenantName) ?></label>
            <input
              id="payment_note_<?= $tenantId ?>"
              type="text"
              name="note"
              maxlength="160"
              value="<?= e($noteValue) ?>"
              placeholder="Optional note"
              class="min-h-11 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
            >
          </div>
        </div>

        <button
          type="submit"
          name="receive_payment"
          value="1"
          data-payment-submit
          data-submitting-label="Saving…"
          class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900 disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
        >
          Receive payment
        </button>
      </form>
    </td>
  </tr>
<?php endforeach; ?>

<?php
declare(strict_types=1);

$portalMonths = $portalMonths ?? [];
$portalMoney = static fn(float $amount): string => 'UGX ' . number_format($amount, 0, '.', ',');
$statusClasses = static function (string $status): string {
    return match (strtolower($status)) {
        'paid', 'done' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'confirmed' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'partial', 'pending' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'unpaid' => 'bg-red-50 text-red-700 ring-red-200',
        default => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
};
$statusLabel = static function (string $status): string {
    return match (strtolower($status)) {
        'paid' => 'Paid',
        'partial' => 'Partial',
        'unpaid' => 'Unpaid',
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'done' => 'Done',
        default => ucfirst($status),
    };
};
$monthLabel = static function (string $month): string {
    $timestamp = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
    return $timestamp ? $timestamp->format('F Y') : $month;
};
?>

<?php if (!$portalMonths): ?>
  <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-sm text-slate-700">
    <p class="font-semibold text-slate-900">No payments recorded</p>
    <p class="mt-1">Payments will appear here after they have been recorded.</p>
  </div>
<?php else: ?>
  <div class="space-y-5 lg:hidden">
    <?php foreach ($portalMonths as $monthGroup): ?>
      <?php $monthStatus = (string)($monthGroup['payment_status'] ?? 'unpaid'); ?>
      <article class="relative pl-8">
        <div class="absolute bottom-0 left-2 top-0 w-px bg-slate-300" aria-hidden="true"></div>
        <div class="absolute left-0 top-1 flex h-5 w-5 items-center justify-center rounded-sm bg-slate-900 text-[9px] font-bold uppercase text-white" aria-hidden="true">
          <?= e(substr((string)($monthGroup['month'] ?? ''), 5, 2)) ?>
        </div>
        <div class="flex flex-col gap-2 border-b border-slate-100 pb-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h3 class="font-alt text-lg font-bold text-slate-900"><?= e($monthLabel((string)$monthGroup['month'])) ?></h3>
            <p class="mt-1 text-sm text-slate-600">
              <?= $monthGroup['balance'] > 0 ? e($portalMoney((float)$monthGroup['balance']) . ' remaining') : 'Paid in full' ?>
            </p>
          </div>
          <span class="inline-flex w-fit items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= e($statusClasses($monthStatus)) ?>">
            <?= e($statusLabel($monthStatus)) ?>
          </span>
        </div>
        <?php if (!empty($monthGroup['payments'])): ?>
          <div class="divide-y divide-slate-100">
            <?php foreach ($monthGroup['payments'] as $payment): ?>
              <?php $verificationStatus = (string)($payment['verification_status'] ?? 'done'); ?>
              <div class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <p class="text-sm font-semibold text-slate-900"><?= e($payment['payment_date'] ?: '—') ?></p>
                  <p class="mt-1 text-xs text-slate-600"><?= e(ucfirst($payment['method'] ?: 'Payment')) ?></p>
                  <?php if (($payment['note'] ?? '') !== ''): ?>
                    <p class="mt-1 text-xs text-slate-500"><?= e($payment['note']) ?></p>
                  <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 sm:flex-col sm:items-end">
                  <span class="font-alt text-base font-bold tabular-nums text-slate-900"><?= e($portalMoney((float)$payment['amount'])) ?></span>
                  <span class="inline-flex items-center rounded-full px-2 py-1 text-[11px] font-semibold ring-1 ring-inset <?= e($statusClasses($verificationStatus)) ?>">
                    <?= e($statusLabel($verificationStatus)) ?>
                  </span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>

  <div class="hidden overflow-x-auto lg:block">
    <table class="min-w-full text-sm">
      <caption class="sr-only">Payment history and verification states</caption>
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          <th scope="col" class="px-4 py-3 text-left font-semibold">Month</th>
          <th scope="col" class="px-4 py-3 text-left font-semibold">Date</th>
          <th scope="col" class="px-4 py-3 text-left font-semibold">Method</th>
          <th scope="col" class="px-4 py-3 text-left font-semibold">Note</th>
          <th scope="col" class="px-4 py-3 text-right font-semibold">Amount</th>
          <th scope="col" class="px-4 py-3 text-left font-semibold">Payment</th>
          <th scope="col" class="px-4 py-3 text-left font-semibold">Verification</th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100">
        <?php foreach ($portalMonths as $monthGroup): ?>
          <?php foreach (($monthGroup['payments'] ?? []) as $payment): ?>
            <?php $verificationStatus = (string)($payment['verification_status'] ?? 'done'); ?>
            <tr class="align-top hover:bg-slate-50">
              <td class="whitespace-nowrap px-4 py-3 font-semibold text-slate-900"><?= e($monthLabel((string)$monthGroup['month'])) ?></td>
              <td class="whitespace-nowrap px-4 py-3"><?= e($payment['payment_date'] ?: '—') ?></td>
              <td class="px-4 py-3"><?= e(ucfirst($payment['method'] ?: 'Payment')) ?></td>
              <td class="max-w-xs px-4 py-3 text-slate-600"><?= e($payment['note'] ?: '—') ?></td>
              <td class="whitespace-nowrap px-4 py-3 text-right font-alt font-bold tabular-nums"><?= e($portalMoney((float)$payment['amount'])) ?></td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= e($statusClasses((string)$monthGroup['payment_status'])) ?>">
                  <?= e($statusLabel((string)$monthGroup['payment_status'])) ?>
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset <?= e($statusClasses($verificationStatus)) ?>">
                  <?= e($statusLabel($verificationStatus)) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

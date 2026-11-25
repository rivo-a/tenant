<?php
declare(strict_types=1);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Urbahan · Rent and repairs for landlords</title>
  <meta name="description" content="Track months of unpaid rent per tenant, and hand maintenance issues to a named member of staff.">
  <link rel="stylesheet" href="assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-slate-50 text-slate-900">

<header class="border-b border-slate-200 bg-white">
  <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
    <div class="flex items-center gap-3">
      <img class="w-9" src="/tenant-system/public/assets/urbahan-logo.png" alt="">
      <span class="font-extrabold">Urbahan</span>
    </div>
    <nav class="flex items-center gap-2">
      <a href="login.php" class="inline-flex min-h-11 items-center rounded-xl px-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Sign in</a>
      <a href="register.php" class="inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">Create account</a>
    </nav>
  </div>
</header>

<main>
  <section class="mx-auto grid max-w-6xl items-start gap-10 px-4 py-12 sm:px-6 lg:grid-cols-[1fr_1.05fr] lg:py-16">

    <div>
      <h1 class="font-alt text-3xl font-bold leading-[1.15] tracking-tight sm:text-4xl">
        Who owes rent,<br>and who is fixing the tap.
      </h1>
      <p class="mt-4 max-w-md text-base leading-7 text-slate-600">
        Urbahan keeps one rent sheet per property: every month a tenant lived there, what was paid, and what is still owed.
        Maintenance issues move from the tenant to a named member of your staff, with each step written down.
      </p>

      <a href="register.php" class="mt-7 inline-flex min-h-12 items-center rounded-xl bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-800">
        Set up your first property
      </a>
      <p class="mt-2 text-xs text-slate-500">Signing up takes you straight to naming the property and adding its rooms.</p>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3">
        <p class="font-semibold">Kiwatule Block B</p>
        <p class="text-xs font-semibold text-slate-500">March 2026</p>
      </div>
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-slate-200 text-left text-xs font-bold uppercase tracking-wide text-slate-500">
            <th class="px-5 py-2 font-bold">Tenant</th>
            <th class="px-3 py-2 font-bold">Room</th>
            <th class="px-3 py-2 font-bold">Months behind</th>
            <th class="px-5 py-2 text-right font-bold">Balance</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 tabular-nums">
          <tr>
            <td class="px-5 py-3 font-medium">Nakato A.</td>
            <td class="px-3 py-3 text-slate-600">B04</td>
            <td class="px-3 py-3"><span class="rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-red-200">3</span></td>
            <td class="px-5 py-3 text-right font-semibold">1,050,000</td>
          </tr>
          <tr>
            <td class="px-5 py-3 font-medium">Mukasa J.</td>
            <td class="px-3 py-3 text-slate-600">B07</td>
            <td class="px-3 py-3"><span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800 ring-1 ring-amber-200">1</span></td>
            <td class="px-5 py-3 text-right font-semibold">350,000</td>
          </tr>
          <tr>
            <td class="px-5 py-3 font-medium">Auma G.</td>
            <td class="px-3 py-3 text-slate-600">B02</td>
            <td class="px-3 py-3"><span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-200">0</span></td>
            <td class="px-5 py-3 text-right font-semibold text-slate-500">0</td>
          </tr>
        </tbody>
      </table>
      <div class="flex items-center justify-between border-t border-slate-200 px-5 py-3">
        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Overdue across the block</p>
        <p class="font-alt text-lg font-bold tabular-nums">1,400,000</p>
      </div>
    </div>
  </section>

  <section class="border-y border-slate-200 bg-white">
    <div class="mx-auto grid max-w-6xl gap-px bg-slate-200 sm:grid-cols-2">
      <article class="bg-white px-5 py-8 sm:px-6">
        <h2 class="font-alt text-xl font-bold">Rent that misses months</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">
          Billing runs from move-in to the current month, at the rent that applied then. A rent increase never rewrites an older month,
          and a tenant's negotiated rent survives a room-wide change.
        </p>
        <ul class="mt-4 space-y-2 text-sm text-slate-700">
          <li>Pending approvals counted separately from collected money</li>
          <li>Ageing buckets and a per-property ledger you can export</li>
          <li>Reminders quoting the same balance the report shows</li>
        </ul>
      </article>

      <article class="bg-white px-5 py-8 sm:px-6">
        <h2 class="font-alt text-xl font-bold">Repairs with a name attached</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">
          A tenant reports the issue with a photo. You hand it to a property manager or rent collector; they see it in their own queue,
          move it forward, and the tenant is told each time.
        </p>
        <div class="mt-4 rounded-xl border border-slate-200 px-4 py-3 text-sm">
          <p class="font-semibold">#18 · Plumbing · B04</p>
          <p class="mt-1 text-slate-600">Leaking elbow under the sink</p>
          <p class="mt-2 flex items-center gap-2 text-xs">
            <span class="rounded-full bg-slate-900 px-2 py-0.5 font-semibold text-white">Assigned</span>
            <span class="text-slate-600">Ssemakula P. · property manager</span>
          </p>
        </div>
      </article>
    </div>
  </section>

  <section class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
    <div class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-6 sm:flex-row sm:items-center sm:justify-between">
      <p class="font-alt text-lg font-bold">Start with one property. Add the next one when it fills up.</p>
      <div class="flex items-center gap-2">
        <a href="register.php" class="inline-flex min-h-11 items-center rounded-xl bg-slate-900 px-4 text-sm font-semibold text-white hover:bg-slate-800">Create account</a>
        <a href="login.php" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Sign in</a>
      </div>
    </div>
  </section>
</main>

<footer class="border-t border-slate-200 py-6">
  <p class="mx-auto max-w-6xl px-4 text-xs text-slate-500 sm:px-6">Urbahan · rent and maintenance for rental property</p>
</footer>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/_portal.php';
renderHeadTeacherPortalStart($user ?? [], 'backups');
?>
<section class="mt-6 space-y-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Data and Backups</h2>
        <p class="mt-1 text-sm text-slate-600">Monitor system backup status and data integrity.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Database Size</p><p class="mt-2 text-3xl font-bold text-slate-900">2.4 GB</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Last Backup</p><p class="mt-2 text-3xl font-bold text-slate-900">3 hrs ago</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Retention</p><p class="mt-2 text-3xl font-bold text-slate-900">30 days</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Backup Frequency</p><p class="mt-2 text-xl font-bold text-slate-900">Daily at 3 AM</p></article>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between"><h3 class="text-lg font-semibold text-slate-900">Cloud Backup - Primary</h3><span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">Healthy</span></div>
            <p class="text-sm text-slate-600">Last backup: <span class="font-semibold text-slate-800">Feb 24, 2026 at 3:00 AM</span></p>
            <p class="mt-2 text-sm text-slate-600">Storage usage: <span class="font-semibold text-slate-800">2.4 GB / 10 GB</span></p>
            <div class="mt-4 h-2 w-full rounded-full bg-slate-200"><div class="h-2 rounded-full bg-emerald-600" style="width:24%"></div></div>
            <div class="mt-5 flex flex-wrap gap-3"><button type="button" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-600">Run Backup Now</button><button type="button" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">View Logs</button></div>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between"><h3 class="text-lg font-semibold text-slate-900">Cloud Backup - Secondary</h3><span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-700">Healthy</span></div>
            <p class="text-sm text-slate-600">Last backup: <span class="font-semibold text-slate-800">Feb 24, 2026 at 3:15 AM</span></p>
            <p class="mt-2 text-sm text-slate-600">Storage usage: <span class="font-semibold text-slate-800">2.3 GB / 10 GB</span></p>
            <div class="mt-4 h-2 w-full rounded-full bg-slate-200"><div class="h-2 rounded-full bg-emerald-600" style="width:23%"></div></div>
            <div class="mt-5 flex flex-wrap gap-3"><button type="button" class="rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-600">Run Backup Now</button><button type="button" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">View Logs</button></div>
        </article>
    </div>
</section>
<?php renderHeadTeacherPortalEnd(); ?>


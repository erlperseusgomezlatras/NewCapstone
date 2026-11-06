<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/role_check.php';
requireRole('head_teacher');
$user = currentUser();

require_once __DIR__ . '/_portal.php';

/** @return string */
function backupFormatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) max(0, $bytes);
    $unitIndex = 0;

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    return ($unitIndex === 0 ? (string) (int) $value : number_format($value, 2)) . ' ' . $units[$unitIndex];
}

$databaseBytes = 0;
$tableCount = 0;
$databaseError = null;

try {
    $stats = getPDO()->query(
        'SELECT COUNT(*) AS table_count, COALESCE(SUM(data_length + index_length), 0) AS database_bytes
         FROM information_schema.tables
         WHERE table_schema = DATABASE()'
    )->fetch();
    $tableCount = (int) ($stats['table_count'] ?? 0);
    $databaseBytes = (int) ($stats['database_bytes'] ?? 0);
} catch (Throwable $exception) {
    // Do not display database implementation details to portal users.
    $databaseError = 'Live database statistics are temporarily unavailable.';
}

$checkedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A T');
renderHeadTeacherPortalStart($user ?? [], 'backups');
?>
<section class="mt-6 space-y-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="text-2xl font-semibold tracking-tight text-slate-900">Data and Backups</h2>
        <p class="mt-1 text-sm text-slate-600">Live information for the database connected to this website.</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-4">
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Database Size</p><p class="mt-2 text-3xl font-bold text-slate-900"><?= htmlspecialchars(backupFormatBytes($databaseBytes)) ?></p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Tables</p><p class="mt-2 text-3xl font-bold text-slate-900"><?= $tableCount ?></p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Data Source</p><p class="mt-2 text-xl font-bold text-slate-900">Protected</p></article>
        <article class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><p class="text-sm font-medium text-slate-500">Connection</p><p class="mt-2 text-xl font-bold <?= $databaseError === null ? 'text-emerald-700' : 'text-rose-700' ?>"><?= $databaseError === null ? 'Connected' : 'Unavailable' ?></p></article>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between"><h3 class="text-lg font-semibold text-slate-900">Live Database Status</h3><span class="rounded-full <?= $databaseError === null ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?> px-2.5 py-1 text-xs font-semibold"><?= $databaseError === null ? 'Connected' : 'Check required' ?></span></div>
            <?php if ($databaseError === null): ?>
                <p class="text-sm text-slate-600">This data is read directly from the protected hosting database.</p>
                <p class="mt-2 text-sm text-slate-600">Last checked: <span class="font-semibold text-slate-800"><?= htmlspecialchars($checkedAt) ?></span></p>
            <?php else: ?>
                <p class="text-sm text-rose-700"><?= htmlspecialchars($databaseError) ?></p>
            <?php endif; ?>
        </article>

        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between"><h3 class="text-lg font-semibold text-slate-900">Hostinger Backups</h3><span class="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-semibold text-sky-700">Managed in hPanel</span></div>
            <p class="text-sm text-slate-600">Backup schedule, retention, restore points, and storage limits are controlled by your Hostinger plan. They are not available to this website, so this page does not invent a backup status.</p>
            <a class="mt-5 inline-flex rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-600" href="https://hpanel.hostinger.com/" target="_blank" rel="noopener noreferrer">Open Hostinger Backups</a>
        </article>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div><h3 class="text-lg font-semibold text-slate-900">Website Response Tester</h3><p class="mt-1 text-sm text-slate-600">Run a fresh browser-based response test for the public homepage.</p></div>
                <button id="pageTestButton" type="button" class="inline-flex items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-600 disabled:cursor-wait disabled:opacity-60">Run page test</button>
            </div>
            <div id="pageTestEmpty" class="mt-6 rounded-xl border border-dashed border-slate-300 bg-slate-50 px-5 py-6 text-sm text-slate-500">No test has run in this browser yet. Results are measured in the current user’s network and device.</div>
            <div id="pageTestResults" class="mt-6 hidden grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl bg-emerald-50 p-4"><p class="text-sm font-medium text-emerald-800">Response time</p><p id="pageTestTime" class="mt-2 text-3xl font-black text-emerald-700">—</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm font-medium text-slate-600">Status</p><p id="pageTestStatus" class="mt-2 text-3xl font-black text-slate-900">—</p></div>
                <div class="rounded-xl bg-slate-50 p-4"><p class="text-sm font-medium text-slate-600">Tested at</p><p id="pageTestDate" class="mt-2 text-sm font-bold text-slate-900">—</p></div>
            </div>
            <p id="pageTestNote" class="mt-4 text-xs text-slate-500">This measures a fresh request to the homepage. It is not a Lighthouse/PageSpeed score and does not access Hostinger hPanel resources.</p>
        </article>
    </div>
</section>
<script>
(() => {
    const button = document.getElementById('pageTestButton');
    const empty = document.getElementById('pageTestEmpty');
    const results = document.getElementById('pageTestResults');
    const time = document.getElementById('pageTestTime');
    const status = document.getElementById('pageTestStatus');
    const date = document.getElementById('pageTestDate');
    const note = document.getElementById('pageTestNote');
    if (!button) return;

    button.addEventListener('click', async () => {
        button.disabled = true;
        button.textContent = 'Testing…';
        note.textContent = 'Sending a fresh request to the public homepage…';
        const started = performance.now();
        try {
            const response = await fetch('<?= htmlspecialchars(appUrl('public/index.php'), ENT_QUOTES, 'UTF-8') ?>?page_test=' + Date.now(), { cache: 'no-store', credentials: 'same-origin' });
            const elapsed = Math.round(performance.now() - started);
            time.textContent = elapsed + ' ms';
            status.textContent = response.ok ? 'Online' : 'HTTP ' + response.status;
            status.className = 'mt-2 text-3xl font-black ' + (response.ok ? 'text-emerald-700' : 'text-rose-700');
            date.textContent = new Date().toLocaleString();
            empty.classList.add('hidden');
            results.classList.remove('hidden');
            note.textContent = response.ok ? 'Test completed successfully from this browser.' : 'The homepage returned an error response.';
        } catch (error) {
            empty.classList.remove('hidden');
            empty.textContent = 'The test could not reach the homepage. Check the internet connection and try again.';
            note.textContent = 'No Hostinger settings were changed.';
        } finally {
            button.disabled = false;
            button.textContent = 'Run page test';
        }
    });
})();
</script>
<?php renderHeadTeacherPortalEnd(); ?>


<?php

declare(strict_types=1);

require_once __DIR__ . '/../middleware/logout_ui.php';

function coordinatorNavItems(): array
{
    return [
        'dashboard' => ['label' => 'Student List', 'path' => '/practicum_system/coordinator/dashboard.php'],
        'checklist' => ['label' => 'Checklist', 'path' => '/practicum_system/coordinator/checklist.php'],
        'journals' => ['label' => 'Journal Submissions', 'path' => '/practicum_system/coordinator/journal_submissions.php'],
        'history' => ['label' => 'History', 'path' => '/practicum_system/coordinator/history.php'],
    ];
}

function coordinatorNavIcon(string $key): string
{
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><rect x="3" y="3" width="7" height="7" rx="1.4"/><rect x="14" y="3" width="7" height="4" rx="1.4"/><rect x="14" y="10" width="7" height="11" rx="1.4"/><rect x="3" y="13" width="7" height="8" rx="1.4"/></svg>',
        'checklist' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M9 11l2 2 4-4"/><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3h6"/></svg>',
        'journals' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 17A2.5 2.5 0 0 0 4 19.5V5a2 2 0 0 1 2-2h14v14"/><path d="M8 7h8"/><path d="M8 11h6"/></svg>',
        'history' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>',
    ];

    return $icons[$key] ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><circle cx="12" cy="12" r="8"/></svg>';
}

function coordinatorSetFlash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['coordinator_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function coordinatorGetFlash(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['coordinator_flash']) || !is_array($_SESSION['coordinator_flash'])) {
        return null;
    }

    $flash = $_SESSION['coordinator_flash'];
    unset($_SESSION['coordinator_flash']);

    return $flash;
}

function coordinatorInitials(string $name): string
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
    if ($parts === []) {
        return 'CO';
    }

    $first = strtoupper(substr($parts[0], 0, 1));
    $last = strtoupper(substr($parts[count($parts) - 1], 0, 1));

    return $first . $last;
}

function coordinatorWeekOptions(array $context): array
{
    if ($context === []) {
        return [];
    }

    $start = new DateTimeImmutable((string) $context['semester_start_date']);
    $end = new DateTimeImmutable((string) $context['semester_end_date']);
    $cursor = $start->modify('monday this week');
    $options = [];
    $index = 1;
    $currentWeek = (new DateTimeImmutable('today'))->modify('monday this week')->format('Y-m-d');

    while ($cursor <= $end) {
        $weekStart = $cursor->format('Y-m-d');
        $weekEnd = $cursor->modify('+4 days')->format('Y-m-d');
        $label = 'Week ' . $index . ' (' . date('M j', strtotime($weekStart)) . '-' . date('j, Y', strtotime($weekEnd)) . ')';
        if ($weekStart === $currentWeek) {
            $label .= ' - CURRENT';
        }
        $options[] = [
            'index' => $index,
            'start' => $weekStart,
            'end' => $weekEnd,
            'label' => $label,
            'is_current' => $weekStart === $currentWeek,
        ];
        $cursor = $cursor->modify('+7 days');
        $index++;
    }

    return $options;
}

function coordinatorSelectedWeek(array $context, ?string $requestedWeek = null): ?array
{
    $options = coordinatorWeekOptions($context);
    if ($options === []) {
        return null;
    }

    if ($requestedWeek !== null) {
        foreach ($options as $option) {
            if ($option['start'] === $requestedWeek) {
                return $option;
            }
        }
    }

    foreach ($options as $option) {
        if ($option['is_current']) {
            return $option;
        }
    }

    return $options[0];
}

function coordinatorTopBadge(string $label, string $tone = 'slate'): string
{
    $map = [
        'green' => 'bg-emerald-100 text-emerald-700 ring-emerald-200',
        'blue' => 'bg-blue-100 text-blue-700 ring-blue-200',
        'orange' => 'bg-amber-50 text-amber-700 ring-amber-100',
        'red' => 'bg-rose-50 text-rose-700 ring-rose-100',
        'slate' => 'bg-slate-100 text-slate-700 ring-slate-200',
    ];
    $class = $map[$tone] ?? $map['slate'];

    return '<span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

function coordinatorContextBadge(?array $context): string
{
    if ($context === null || $context === []) {
        return coordinatorTopBadge('NO ACTIVE SECTION', 'orange');
    }

    $sectionStatus = (string) ($context['section_status'] ?? '');
    $semesterStatus = (string) ($context['semester_status'] ?? '');
    $yearStatus = (string) ($context['year_status'] ?? '');

    if ($sectionStatus === 'active' && $semesterStatus === 'active' && $yearStatus === 'active') {
        return coordinatorTopBadge('ACTIVE', 'green');
    }

    if ($sectionStatus === 'archived' || $semesterStatus === 'archived' || $yearStatus === 'archived') {
        return coordinatorTopBadge('ARCHIVED', 'red');
    }

    if ($sectionStatus === 'inactive' || $semesterStatus === 'closed' || $semesterStatus === 'planned') {
        return coordinatorTopBadge('INACTIVE', 'orange');
    }

    return coordinatorTopBadge('STATUS UNKNOWN', 'slate');
}

function renderCoordinatorPortalStart(
    array $user,
    string $activeKey,
    string $pageTitle,
    ?array $context = null,
    string $headerAction = ''
): void {
    $navItems = coordinatorNavItems();
    $displayName = trim((string) ($user['name'] ?? 'Coordinator'));
    $displayEmail = trim((string) ($user['email'] ?? ''));
    $initials = coordinatorInitials($displayName);
    $sectionLabel = trim((string) (($context['section_code'] ?? '') !== '' ? $context['section_code'] : ($context['section_name'] ?? 'Portal')));
    $semesterLabel = trim((string) ($context['semester_name'] ?? ''));
    $schoolYearLabel = trim((string) ($context['year_label'] ?? ''));
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            500: '#16a34a',
                            600: '#15803d',
                            700: '#166534',
                            800: '#14532d',
                            900: '#0f3d25'
                        }
                    },
                    boxShadow: {
                        panel: '0 10px 30px rgba(15, 23, 42, 0.06)'
                    }
                }
            }
        };
    </script>
    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(15, 23, 42, 0.03), transparent 28%),
                linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
        }
        [data-reveal] {
            opacity: 0;
            transform: translateY(18px);
            transition: opacity .55s ease, transform .55s ease;
        }
        [data-reveal].is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .scroll-slim::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .scroll-slim::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, .5);
            border-radius: 999px;
        }
    </style>
</head>
<body class="min-h-screen text-slate-900 antialiased">
<div class="relative min-h-screen lg:flex">
    <div id="sidebarBackdrop" class="fixed inset-0 z-30 hidden bg-slate-950/40 backdrop-blur-sm lg:hidden"></div>

    <aside id="sidebar"
        class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col overflow-hidden bg-gradient-to-b from-brand-700 via-brand-700 to-brand-900 text-white shadow-2xl transition-transform duration-300 lg:sticky lg:top-0 lg:h-screen lg:translate-x-0">
        <div class="border-b border-white/10 px-6 py-7">
            <div class="flex items-center gap-4">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-base font-bold tracking-[0.2em] text-white/90">
                    CO
                </div>
                <div class="min-w-0">
                    <p class="truncate text-xs font-extrabold uppercase tracking-[0.18em] text-white/95">Coordinator Portal</p>
                    <p class="mt-1 text-[11px] uppercase tracking-[0.18em] text-emerald-100/80">PHINMA Education</p>
                </div>
            </div>
        </div>

        <div class="scroll-slim flex-1 overflow-y-auto px-4 py-6">
            <nav class="space-y-2">
                <?php foreach ($navItems as $key => $item): ?>
                    <a href="<?= htmlspecialchars($item['path']) ?>"
                        class="<?= $activeKey === $key ? 'bg-white/14 text-white shadow-lg ring-1 ring-white/10' : 'text-emerald-50/90 hover:bg-white/10 hover:text-white' ?> flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold transition">
                        <?= coordinatorNavIcon($key) ?>
                        <span><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="border-t border-white/10 p-4">
            <a href="/practicum_system/coordinator/dashboard.php"
                class="mb-4 flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold text-emerald-50/90 transition hover:bg-white/10 hover:text-white">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.4-1.7 1.7 1.7 0 0 0-1.8.5l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1 1.7 1.7 0 0 0-1.1-.4H2.9a2 2 0 1 1 0-4H3a1.7 1.7 0 0 0 1.7-1.4 1.7 1.7 0 0 0-.5-1.8l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6 1.7 1.7 0 0 0 .4-1.1V2.9a2 2 0 1 1 4 0V3a1.7 1.7 0 0 0 1.4 1.7 1.7 1.7 0 0 0 1.8-.5l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.26.37.4.82.4 1.3s-.14.93-.4 1.3z"/></svg>
                <span>Settings</span>
            </a>
            <div class="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/5 px-4 py-3">
                <div class="flex h-11 w-11 items-center justify-center rounded-full border border-white/15 bg-white/10 text-sm font-bold text-white"><?= htmlspecialchars($initials) ?></div>
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold"><?= htmlspecialchars($displayName) ?></div>
                    <div class="truncate text-xs text-emerald-100/80">Coordinator</div>
                </div>
            </div>
        </div>
    </aside>

    <div class="min-w-0 flex-1">
        <header class="sticky top-0 z-20 border-b border-slate-200/80 bg-white/85 backdrop-blur-xl">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6 lg:px-8">
                <div class="flex min-w-0 items-center gap-3">
                    <button id="sidebarToggle" type="button" class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 text-slate-700 lg:hidden">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-5 w-5"><path d="M3 6h18"/><path d="M3 12h18"/><path d="M3 18h18"/></svg>
                    </button>
                    <div class="min-w-0">
                        <div class="truncate text-base font-bold text-slate-900">Coordinator <span class="font-medium text-slate-500">/ <?= htmlspecialchars($sectionLabel !== '' ? $sectionLabel : 'Portal') ?></span></div>
                    </div>
                </div>

                <div class="hidden items-center gap-3 lg:flex">
                    <?php if ($schoolYearLabel !== ''): ?>
                        <span class="text-sm text-slate-600"><?= htmlspecialchars($schoolYearLabel) ?></span>
                    <?php endif; ?>
                    <?php if ($semesterLabel !== ''): ?>
                        <span class="text-sm text-slate-500"><?= htmlspecialchars($semesterLabel) ?></span>
                    <?php endif; ?>
                    <?= coordinatorContextBadge($context) ?>
                    <?= $headerAction ?>
                    <?php if ($displayEmail !== ''): ?>
                        <span class="max-w-[240px] truncate text-sm text-slate-500"><?= htmlspecialchars($displayEmail) ?></span>
                    <?php endif; ?>
                    <a href="/practicum_system/public/logout.php" class="inline-flex items-center rounded-2xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 transition hover:border-slate-400 hover:bg-slate-50" data-logout-trigger>Logout</a>
                </div>
            </div>

            <div class="border-t border-slate-200/70 px-4 py-3 lg:hidden">
                <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-2 text-sm">
                    <?php if ($schoolYearLabel !== ''): ?>
                        <span class="text-slate-600"><?= htmlspecialchars($schoolYearLabel) ?></span>
                    <?php endif; ?>
                    <?php if ($semesterLabel !== ''): ?>
                        <span class="text-slate-500"><?= htmlspecialchars($semesterLabel) ?></span>
                    <?php endif; ?>
                    <?= coordinatorContextBadge($context) ?>
                    <?php if ($displayEmail !== ''): ?>
                        <span class="truncate text-slate-500"><?= htmlspecialchars($displayEmail) ?></span>
                    <?php endif; ?>
                    <?= $headerAction ?>
                    <a href="/practicum_system/public/logout.php" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-4 py-2 font-semibold text-slate-900" data-logout-trigger>Logout</a>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <?php
}

function renderCoordinatorPortalEnd(string $extraScript = ''): void
{
    ?>
        </main>
    </div>
</div>
<?php renderLogoutUi('/practicum_system/public/logout.php'); ?>
<script>
    (function () {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const backdrop = document.getElementById('sidebarBackdrop');

        function openSidebar() {
            sidebar?.classList.remove('-translate-x-full');
            backdrop?.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function closeSidebar() {
            sidebar?.classList.add('-translate-x-full');
            backdrop?.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        sidebarToggle?.addEventListener('click', openSidebar);
        backdrop?.addEventListener('click', closeSidebar);

        window.addEventListener('resize', function () {
            if (window.innerWidth >= 1024) {
                backdrop?.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        });

        const revealItems = Array.from(document.querySelectorAll('[data-reveal]'));
        revealItems.forEach(function (item, index) {
            window.setTimeout(function () {
                item.classList.add('is-visible');
            }, 90 * index);
        });
    })();
</script>
<?= $extraScript ?>
</body>
</html>
    <?php
}

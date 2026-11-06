<?php
declare(strict_types=1);
require_once __DIR__ . '/../middleware/role_check.php';
require_once __DIR__ . '/../middleware/logout_ui.php';
requireRole('supervisor');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/SupervisorService.php';

function supervisorNavItems(): array { return [
    'students' => ['label' => 'Assigned Students', 'path' => appUrl('supervisor/assigned_students.php'), 'icon' => 'users'],
    'evaluations' => ['label' => 'Evaluate Students', 'path' => appUrl('supervisor/evaluate_students.php'), 'icon' => 'clipboard'],
    'profile' => ['label' => 'Profile', 'path' => appUrl('supervisor/profile.php'), 'icon' => 'user'],
]; }

function supervisorInitials(array $user): string {
    $name = trim((string) ($user['name'] ?? '')) ?: (string) ($user['email'] ?? 'Supervisor');
    $initials = '';
    foreach (array_slice(preg_split('/\s+/', $name) ?: [], 0, 2) as $part) $initials .= strtoupper(substr($part, 0, 1));
    return $initials ?: 'SP';
}

function supervisorNavIcon(string $icon): string {
    $paths = [
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3a3 3 0 0 1 6 0v1M9 10h6M9 14h6M9 18h4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    ];
    return '<svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$icon] ?? $paths['user']) . '</svg>';
}

function renderSupervisorStart(array $user, string $active): void {
    $items = supervisorNavItems();
    $displayName = trim((string) ($user['name'] ?? '')) ?: (string) ($user['email'] ?? 'School Supervisor');
    $email = (string) ($user['email'] ?? ''); ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Supervisor Portal</title><link rel="icon" type="image/png" href="/assets/images/logo_college.png"><script src="https://cdn.tailwindcss.com"></script></head>
<body class="min-h-screen bg-slate-50 text-slate-800">
<div id="supervisorSidebarBackdrop" class="fixed inset-0 z-30 hidden bg-slate-950/45 backdrop-blur-[1px] lg:hidden"></div>
<aside id="supervisorSidebar" class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col overflow-hidden bg-gradient-to-b from-emerald-700 via-emerald-800 to-emerald-950 text-white shadow-2xl transition-transform duration-300 lg:translate-x-0">
 <div class="border-b border-white/10 px-6 py-7"><div id="sidebarBrandWrap" class="flex items-center gap-4"><div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-white/10 shadow-inner ring-1 ring-white/10"><img id="sidebarBrandLogo" src="../assets/images/logo_coc.png" alt="Cagayan de Oro College" class="h-12 w-auto object-contain"></div><div class="min-w-0"><p class="text-[11px] font-extrabold leading-4 tracking-[0.19em] text-white">CAGAYAN DE ORO<br>COLLEGE</p><p class="mt-1 text-[10px] font-semibold tracking-[0.18em] text-emerald-200">PHINMA EDUCATION</p></div></div><div class="mt-6 flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.06] px-4 py-3"><span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-400/20 text-xs font-bold tracking-wide text-emerald-50">SP</span><div><p class="text-sm font-bold">Supervisor Portal</p><p class="text-xs text-emerald-100/70">School placement review</p></div></div></div>
 <div class="scroll-slim flex-1 overflow-y-auto px-4 py-6"><p class="mb-3 px-3 text-[10px] font-bold tracking-[0.18em] text-emerald-200/80">WORKSPACE</p><nav class="space-y-2" aria-label="Supervisor navigation"><?php foreach ($items as $key => $item): ?><a class="<?= $active === $key ? 'bg-white/15 text-white shadow-lg ring-1 ring-white/10' : 'text-emerald-50/90 hover:bg-white/10 hover:text-white' ?> flex items-center gap-3 rounded-2xl px-4 py-3 text-sm font-semibold transition" href="<?= htmlspecialchars($item['path']) ?>"><span class="<?= $active === $key ? 'bg-emerald-400/25 text-white' : 'bg-white/[0.08] text-emerald-100' ?> flex h-9 w-9 items-center justify-center rounded-xl transition"><?= supervisorNavIcon($item['icon']) ?></span><?= htmlspecialchars($item['label']) ?></a><?php endforeach; ?></nav></div>
 <div class="border-t border-white/10 p-4"><a href="<?= htmlspecialchars($items['profile']['path']) ?>" class="mb-3 flex items-center gap-3 rounded-2xl border border-white/10 bg-white/[0.07] px-3 py-3 transition hover:bg-white/[0.12]"><span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-400 text-sm font-extrabold text-emerald-950"><?= htmlspecialchars(supervisorInitials($user)) ?></span><span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold"><?= htmlspecialchars($displayName) ?></span><span class="block truncate text-xs text-emerald-100/75"><?= htmlspecialchars($email !== '' ? $email : 'School Supervisor') ?></span></span></a><?php renderSignOutButton('', true, 'sign-out-button--compact'); ?></div>
</aside>
<main class="min-h-screen lg:ml-72"><div class="w-full p-4 sm:p-5 lg:p-6"><header class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white px-4 py-4 shadow-sm sm:px-5"><button id="supervisorSidebarToggle" type="button" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 text-slate-600 transition hover:bg-slate-50 lg:hidden" aria-label="Open navigation" aria-expanded="false"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button><div class="min-w-0"><p class="text-sm font-medium text-slate-500">School Supervisor</p><h2 class="truncate text-xl font-bold text-slate-900"><?= htmlspecialchars($displayName) ?></h2></div></header>
<?php }

function renderSupervisorEnd(): void { ?>
</div></main><?php renderLogoutUi(appUrl('public/logout.php')); ?>
<script>(()=>{const sidebar=document.getElementById('supervisorSidebar'),backdrop=document.getElementById('supervisorSidebarBackdrop'),toggle=document.getElementById('supervisorSidebarToggle');if(!sidebar||!backdrop||!toggle)return;const close=()=>{sidebar.classList.add('-translate-x-full');backdrop.classList.add('hidden');toggle.setAttribute('aria-expanded','false')};const open=()=>{sidebar.classList.remove('-translate-x-full');backdrop.classList.remove('hidden');toggle.setAttribute('aria-expanded','true')};toggle.addEventListener('click',()=>sidebar.classList.contains('-translate-x-full')?open():close());backdrop.addEventListener('click',close);window.addEventListener('keydown',event=>{if(event.key==='Escape')close()})})();</script>
</body></html>
<?php }

<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Shared authenticated-layout action. Use this in every portal sidebar so the
 * visual treatment and confirmation behaviour cannot drift between roles.
 */
function renderSignOutButton(string $id = '', bool $showLabel = true, string $extraClasses = ''): void
{
    $logoutUrl = htmlspecialchars(appUrl('public/logout.php'), ENT_QUOTES, 'UTF-8');
    $safeId = $id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '';
    $labelClass = $showLabel ? '' : ' sr-only';
    ?>
    <a<?= $safeId ?> href="<?= $logoutUrl ?>" data-logout-trigger aria-label="Sign out of your account"
        class="sign-out-button <?= htmlspecialchars($extraClasses, ENT_QUOTES, 'UTF-8') ?>">
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-5 w-5 shrink-0"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
        <span class="<?= $labelClass ?>">Sign Out</span>
    </a>
    <?php
}

function renderLogoutUi(?string $logoutUrl = null): void
{
    $logoutUrl ??= appUrl('public/logout.php');
    $safeUrl = htmlspecialchars($logoutUrl, ENT_QUOTES, 'UTF-8');
    ?>
    <div id="loBackdrop" class="hidden fixed inset-0 z-[9998] bg-slate-950/45 backdrop-blur-sm" aria-hidden="true"></div>

    <div id="loModalWrap" class="hidden fixed inset-0 z-[9999] items-center justify-center p-4" role="dialog" aria-modal="true" aria-labelledby="loModalTitle">
        <div class="w-full max-w-md overflow-hidden rounded-[28px] border border-emerald-200/80 bg-white shadow-[0_32px_90px_rgba(16,185,129,0.18)]">
            <div class="bg-gradient-to-r from-emerald-600 via-emerald-500 to-lime-400 px-6 py-5 text-white">
                <p class="text-xs font-bold uppercase tracking-[0.3em] text-emerald-50/90">Secure Exit</p>
                <h2 id="loModalTitle" class="mt-2 text-2xl font-black tracking-tight">Log Out Now?</h2>
                <p class="mt-2 text-sm text-emerald-50/90">You are about to end this session and return to the login page.</p>
            </div>
            <div class="space-y-5 px-6 py-6">
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50/70 px-4 py-4 text-sm text-emerald-900">
                    Your current work stays saved. This will only close the active session.
                </div>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button id="loCancelBtn" type="button" class="inline-flex items-center justify-center rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">Cancel</button>
                    <button id="loConfirmBtn" type="button" class="inline-flex items-center justify-center rounded-2xl bg-gradient-to-r from-emerald-600 to-green-500 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-emerald-500/30 transition hover:from-emerald-700 hover:to-green-600">Logout</button>
                </div>
            </div>
        </div>
    </div>

    <div id="loLoading" class="hidden fixed inset-0 z-[10000] items-center justify-center bg-slate-950/50 px-4 backdrop-blur-md" aria-live="assertive" aria-label="Signing out">
        <div class="relative w-full max-w-sm overflow-hidden rounded-[28px] border border-emerald-100 bg-white shadow-[0_32px_100px_rgba(16,185,129,0.24)]">
            <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-400 via-green-500 to-lime-400"></div>
            <div class="relative px-8 py-9 text-center">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-50 ring-8 ring-emerald-50/70">
                    <div class="h-9 w-9 animate-spin rounded-full border-[3px] border-emerald-100 border-t-emerald-600"></div>
                </div>
                <h3 class="mt-6 text-2xl font-black tracking-tight text-slate-900">Signing you out</h3>
                <p class="mt-2 text-sm text-slate-500">Closing your session securely</p>
                <div class="mt-7 h-1.5 overflow-hidden rounded-full bg-emerald-50">
                    <div id="loProgress" class="lo-progress h-full rounded-full bg-gradient-to-r from-emerald-500 to-lime-400"></div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .sign-out-button {
            display: flex;
            width: 100%;
            height: 48px;
            align-items: center;
            justify-content: center;
            gap: 0.625rem;
            border-radius: 12px;
            background: linear-gradient(90deg, #E11D48, #DC2626);
            color: #fff;
            font-size: .875rem;
            font-weight: 600;
            box-shadow: 0 8px 18px rgba(190, 24, 93, .22);
            transition: filter 200ms ease, transform 200ms ease, box-shadow 200ms ease;
        }
        .sign-out-button:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 10px 22px rgba(190, 24, 93, .30); }
        .sign-out-button:active { transform: scale(.98); }
        .sign-out-button:focus-visible { outline: 3px solid #fff; outline-offset: 3px; box-shadow: 0 0 0 6px rgba(225, 29, 72, .55); }
        .sign-out-button.sign-out-button--compact { height: 40px; border-radius: 10px; font-weight: 500; box-shadow: 0 4px 10px rgba(190, 24, 93, .16); }
        .sign-out-button.sign-out-button--compact svg { width: 16px; height: 16px; }
        @keyframes logout-progress { from { width: 0; } to { width: 100%; } }
        .lo-progress { width: 0; }
        .lo-progress.is-active { animation: logout-progress 1.5s linear forwards; }
    </style>

    <script>
        (function () {
            const LOGOUT_URL = <?= json_encode($safeUrl) ?>;
            const backdrop = document.getElementById('loBackdrop');
            const modalWrap = document.getElementById('loModalWrap');
            const cancelBtn = document.getElementById('loCancelBtn');
            const confirmBtn = document.getElementById('loConfirmBtn');
            const loading = document.getElementById('loLoading');
            const progress = document.getElementById('loProgress');
            let logoutTimerId = null;

            function hideConfirm() {
                modalWrap.classList.add('hidden');
                modalWrap.classList.remove('flex');
                backdrop.classList.add('hidden');
            }

            function showConfirm() {
                modalWrap.classList.remove('hidden');
                modalWrap.classList.add('flex');
                backdrop.classList.remove('hidden');
            }

            function startLogout() {
                hideConfirm();
                loading.classList.remove('hidden');
                loading.classList.add('flex');
                progress?.classList.remove('is-active');
                void progress?.offsetWidth;
                progress?.classList.add('is-active');

                if (logoutTimerId) {
                    clearTimeout(logoutTimerId);
                }

                logoutTimerId = window.setTimeout(() => {
                    window.location.href = LOGOUT_URL;
                }, 1500);
            }

            document.querySelectorAll('[data-logout-trigger]').forEach((el) => {
                el.addEventListener('click', (event) => {
                    event.preventDefault();
                    showConfirm();
                });
            });

            backdrop?.addEventListener('click', hideConfirm);
            cancelBtn?.addEventListener('click', hideConfirm);
            confirmBtn?.addEventListener('click', startLogout);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    hideConfirm();
                }
            });
        })();
    </script>
    <script>
        // Shared live session for authenticated portals that do not provide a
        // page-specific live engine. Head Teacher pages use their richer
        // implementation above; this covers Coordinator, Supervisor, and the
        // Student dashboard with the same cross-tab behavior.
        (function () {
            if (window.PhinmaPortalLiveSession) return;

            const channelName = 'phinma-head-teacher-portal-state';
            const stateUrl = <?= json_encode(appUrl('api/portal_live_state.php')) ?>;
            const channel = 'BroadcastChannel' in window ? new BroadcastChannel(channelName) : null;
            let knownVersion = '';

            function isEditing() {
                const active = document.activeElement;
                return active instanceof HTMLInputElement
                    || active instanceof HTMLTextAreaElement
                    || active instanceof HTMLSelectElement
                    || active?.isContentEditable === true
                    || Boolean(document.querySelector('[role="dialog"]:not(.hidden), .fixed.inset-0.z-50:not(.hidden)'));
            }

            function refreshIfSafe() {
                if (!isEditing()) window.location.reload();
            }

            async function checkState() {
                if (document.visibilityState !== 'visible') return;
                try {
                    const response = await fetch(stateUrl, {cache: 'no-store', credentials: 'same-origin', headers: {Accept: 'application/json'}});
                    const payload = await response.json();
                    if (!response.ok || !payload.success || !payload.state_version) return;
                    if (knownVersion && knownVersion !== payload.state_version) {
                        refreshIfSafe();
                        return;
                    }
                    knownVersion = payload.state_version;
                } catch (_) {}
            }

            function receive(message) {
                window.setTimeout(() => {
                    if (message?.force === true) refreshIfSafe();
                    else checkState();
                }, Math.max(250, Number(message?.delay || 700)));
            }

            window.PhinmaPortalLiveSession = {
                notify(delay = 700, force = false) {
                    const message = {type: 'portal-state-changed', delay, force};
                    channel?.postMessage(message);
                    try { localStorage.setItem(channelName, JSON.stringify(message)); } catch (_) {}
                },
            };
            channel?.addEventListener('message', (event) => {
                if (event.data?.type === 'portal-state-changed') receive(event.data);
            });
            window.addEventListener('storage', (event) => {
                if (event.key !== channelName) return;
                try { receive(JSON.parse(event.newValue || '{}')); } catch (_) { checkState(); }
            });
            document.addEventListener('submit', (event) => {
                if (event.defaultPrevented || !(event.target instanceof HTMLFormElement)) return;
                if ((event.target.method || 'get').toLowerCase() === 'post') {
                    window.PhinmaPortalLiveSession.notify(3000, true);
                }
            });

            const originalFetch = window.fetch.bind(window);
            window.fetch = async function (...args) {
                const response = await originalFetch(...args);
                const request = args[0];
                const options = args[1] || {};
                const method = String(options.method || (request instanceof Request ? request.method : 'GET')).toUpperCase();
                const url = new URL(request instanceof Request ? request.url : String(request), window.location.href);
                if (response.ok && method !== 'GET' && url.origin === window.location.origin
                    && !url.pathname.endsWith('/api/head_teacher_admin_verify.php')) {
                    window.PhinmaPortalLiveSession.notify(500, true);
                }
                return response;
            };

            document.addEventListener('visibilitychange', checkState);
            checkState();
            window.setInterval(checkState, 15000);
        })();
    </script>
    <?php
}

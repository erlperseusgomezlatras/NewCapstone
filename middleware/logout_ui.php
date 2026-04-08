<?php

declare(strict_types=1);

function renderLogoutUi(string $logoutUrl = '/practicum_system/public/logout.php'): void
{
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

    <div id="loLoading" class="hidden fixed inset-0 z-[10000] items-center justify-center bg-slate-950/50 px-4 backdrop-blur-md" aria-live="assertive">
        <div class="relative w-full max-w-md overflow-hidden rounded-[32px] border border-emerald-200/70 bg-white shadow-[0_32px_100px_rgba(16,185,129,0.24)]">
            <div class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-emerald-400 via-green-500 to-lime-400"></div>
            <div class="absolute -right-10 -top-10 h-32 w-32 rounded-full bg-emerald-200/40 blur-2xl"></div>
            <div class="absolute -left-8 bottom-0 h-28 w-28 rounded-full bg-lime-200/40 blur-2xl"></div>
            <div class="relative px-8 py-8 text-center">
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-emerald-500 to-green-600 shadow-lg shadow-emerald-500/30">
                    <div class="h-10 w-10 animate-spin rounded-full border-4 border-white/30 border-t-white"></div>
                </div>
                <h3 class="mt-6 text-3xl font-black tracking-tight text-slate-900">Logging out</h3>
                <p class="mt-2 text-sm font-medium text-emerald-700">Wrapping up your session securely</p>
                <div class="mt-6 flex items-center justify-center gap-3">
                    <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-emerald-500"></span>
                    <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-green-500 [animation-delay:180ms]"></span>
                    <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-lime-500 [animation-delay:360ms]"></span>
                </div>
                <div id="loCountNum" class="mt-7 text-6xl font-black tracking-tight text-emerald-600">3</div>
                <div class="mt-2 text-xs font-bold uppercase tracking-[0.35em] text-slate-400">Seconds Remaining</div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const LOGOUT_URL = <?= json_encode($safeUrl) ?>;
            const backdrop = document.getElementById('loBackdrop');
            const modalWrap = document.getElementById('loModalWrap');
            const cancelBtn = document.getElementById('loCancelBtn');
            const confirmBtn = document.getElementById('loConfirmBtn');
            const loading = document.getElementById('loLoading');
            const countNum = document.getElementById('loCountNum');
            let intervalId = null;

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

            function startCountdownAndLogout() {
                hideConfirm();
                loading.classList.remove('hidden');
                loading.classList.add('flex');
                let count = 3;
                countNum.textContent = String(count);

                if (intervalId) {
                    clearInterval(intervalId);
                }

                intervalId = setInterval(() => {
                    count -= 1;
                    countNum.textContent = String(Math.max(count, 0));
                    if (count <= 0) {
                        clearInterval(intervalId);
                        window.location.href = LOGOUT_URL;
                    }
                }, 1000);
            }

            document.querySelectorAll('[data-logout-trigger]').forEach((el) => {
                el.addEventListener('click', (event) => {
                    event.preventDefault();
                    showConfirm();
                });
            });

            backdrop?.addEventListener('click', hideConfirm);
            cancelBtn?.addEventListener('click', hideConfirm);
            confirmBtn?.addEventListener('click', startCountdownAndLogout);
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    hideConfirm();
                }
            });
        })();
    </script>
    <?php
}

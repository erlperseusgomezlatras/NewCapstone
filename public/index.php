<?php
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
$basePath = preg_replace('#/public$#', '', $scriptDir);
if ($basePath === '.' || $basePath === null) {
    $basePath = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHINMA | Practicum Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .reveal {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .reveal.in {
            opacity: 1;
            transform: translateY(0);
        }
        .float-slow {
            animation: floatY 8s ease-in-out infinite;
        }
        .float-fast {
            animation: floatY 5s ease-in-out infinite;
        }
        @keyframes floatY {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
    </style>
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 antialiased">
    <header class="sticky top-0 z-30 border-b border-slate-200/70 bg-white/90 backdrop-blur">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="<?= htmlspecialchars($basePath) ?>/" class="flex items-center gap-3" aria-label="PHINMA Education Home">
                <img src="<?= htmlspecialchars($basePath) ?>/assets/images/logo.png" alt="PHINMA Education" class="h-11 w-11 rounded-full border border-slate-200 p-1">
                <div>
                    <p class="text-sm font-semibold leading-tight">PHINMA Education</p>
                    <p class="text-xs text-slate-500">Practicum Management System</p>
                </div>
            </a>
            <a href="<?= htmlspecialchars($basePath) ?>/public/login.php" class="inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition duration-300 hover:scale-105 hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2">Login</a>
        </div>
    </header>

    <main>
        <section class="relative overflow-hidden bg-gradient-to-br from-emerald-900 via-emerald-700 to-teal-500">
            <div class="pointer-events-none absolute -left-12 top-16 h-48 w-48 rounded-full bg-emerald-300/35 blur-2xl float-fast"></div>
            <div class="pointer-events-none absolute right-8 top-12 h-40 w-40 rounded-full bg-cyan-100/30 blur-2xl float-slow"></div>
            <div class="pointer-events-none absolute bottom-10 left-1/2 h-56 w-56 -translate-x-1/2 rounded-full bg-lime-100/20 blur-3xl float-fast"></div>

            <div class="relative mx-auto grid w-full max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:grid-cols-2 lg:items-center lg:gap-16 lg:px-8 lg:py-24">
                <div class="reveal text-white">
                    <p class="mb-3 inline-flex items-center rounded-full bg-white/15 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-emerald-100">
                        PHINMA Cagayan De Oro College
                    </p>
                    <h1 class="text-4xl font-black leading-tight sm:text-5xl lg:text-6xl">
                        Practicum Management
                        <span class="block bg-gradient-to-r from-lime-200 via-cyan-100 to-white bg-clip-text text-transparent">Monitoring System</span>
                    </h1>
                    <p class="mt-5 max-w-xl text-sm leading-7 text-emerald-50 sm:text-base">
                        A comprehensive platform for managing and tracking OJT students, ensuring seamless coordination between students, supervisors, and coordinators.
                    </p>
                    <div class="mt-7 flex flex-wrap items-center gap-3">
                        <a href="<?= htmlspecialchars($basePath) ?>/public/login.php" class="inline-flex items-center rounded-xl bg-white px-5 py-3 text-sm font-bold text-emerald-800 shadow-sm transition duration-300 hover:scale-105 hover:bg-emerald-50">Get Started</a>
                        <a href="#features" class="inline-flex items-center rounded-xl border border-white/40 px-5 py-3 text-sm font-semibold text-white transition duration-300 hover:scale-105 hover:bg-white/10">Explore Features</a>
                    </div>
                </div>
                <div class="reveal flex justify-center lg:justify-end">
                    <div class="rounded-3xl bg-white/10 p-4 shadow-2xl ring-1 ring-white/20 backdrop-blur">
                        <img src="<?= htmlspecialchars($basePath) ?>/assets/images/logo_college.png" alt="Cagayan De Oro College Logo" class="h-72 w-72 rounded-2xl object-contain sm:h-80 sm:w-80">
                    </div>
                </div>
            </div>
        </section>

        <section id="features" class="mx-auto w-full max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-20">
            <div class="reveal text-center">
                <h2 class="text-3xl font-extrabold leading-tight sm:text-4xl">
                    Powerful Features for
                    <span class="bg-gradient-to-r from-emerald-600 to-cyan-600 bg-clip-text text-transparent">Better Management</span>
                </h2>
                <p class="mx-auto mt-3 max-w-2xl text-sm text-slate-600 sm:text-base">
                    Built for schools that need clear practicum monitoring, accurate reporting, and smoother coordination.
                </p>
            </div>

            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <article class="reveal rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:scale-105 hover:shadow-xl">
                    <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">TT</div>
                    <h3 class="text-lg font-bold">Time Tracking</h3>
                    <p class="mt-2 text-sm text-slate-600">Accurate daily attendance capture with structured hour computation.</p>
                </article>
                <article class="reveal rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:scale-105 hover:shadow-xl">
                    <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">RM</div>
                    <h3 class="text-lg font-bold">Report Management</h3>
                    <p class="mt-2 text-sm text-slate-600">Consolidated records for attendance, journals, and practicum progress.</p>
                </article>
                <article class="reveal rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:scale-105 hover:shadow-xl">
                    <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">PA</div>
                    <h3 class="text-lg font-bold">Progress Analytics</h3>
                    <p class="mt-2 text-sm text-slate-600">Weekly and semester summaries to identify students needing support.</p>
                </article>
                <article class="reveal rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:scale-105 hover:shadow-xl">
                    <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">RA</div>
                    <h3 class="text-lg font-bold">Role-Based Access</h3>
                    <p class="mt-2 text-sm text-slate-600">Dedicated views for head teachers, coordinators, and students.</p>
                </article>
                <article class="reveal rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:scale-105 hover:shadow-xl">
                    <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">EV</div>
                    <h3 class="text-lg font-bold">Evaluation Workflow</h3>
                    <p class="mt-2 text-sm text-slate-600">Checklist and journal review processes for consistent feedback.</p>
                </article>
                <article class="reveal rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm transition duration-300 hover:-translate-y-1 hover:scale-105 hover:shadow-xl">
                    <div class="mb-4 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">SR</div>
                    <h3 class="text-lg font-bold">Secure and Reliable</h3>
                    <p class="mt-2 text-sm text-slate-600">Designed for dependable school operations and safer data handling.</p>
                </article>
            </div>
        </section>

        <section class="bg-gradient-to-br from-emerald-700 to-emerald-900 py-16 text-white lg:py-20">
            <div class="mx-auto grid w-full max-w-7xl gap-10 px-4 sm:px-6 lg:grid-cols-[1.3fr_1fr] lg:gap-12 lg:px-8">
                <div class="reveal">
                    <h2 class="text-3xl font-extrabold sm:text-4xl">About PHINMA Cagayan De Oro College</h2>
                    <p class="mt-3 text-sm text-emerald-100">Max Suniel St, Carmen, Cagayan de Oro City, Misamis Oriental, Philippines 9000</p>
                    <p class="mt-4 text-sm leading-7 text-emerald-50">
                        PHINMA Cagayan De Oro College is committed to delivering quality education and practical training opportunities. The Practicum Management System helps teams monitor students through every OJT stage with clear accountability.
                    </p>
                    <p class="mt-4 text-sm leading-7 text-emerald-50">
                        We collaborate with industry partners to provide real-world learning that prepares students for successful careers.
                    </p>
                </div>
                <aside class="reveal rounded-2xl bg-white p-7 text-slate-800 shadow-xl">
                    <h3 class="text-2xl font-extrabold">Why Choose Us?</h3>
                    <ul class="mt-5 space-y-3 text-sm">
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-emerald-600">OK</span><span>Comprehensive monitoring and support workflow</span></li>
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-emerald-600">OK</span><span>Strong industry partnerships and connections</span></li>
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-emerald-600">OK</span><span>Dedicated coordinators and supervisors</span></li>
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-emerald-600">OK</span><span>Regular feedback and evaluation cycles</span></li>
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-emerald-600">OK</span><span>Career development and placement support</span></li>
                    </ul>
                </aside>
            </div>
        </section>
    </main>

    <footer class="bg-emerald-950 py-12 text-emerald-50">
        <div class="mx-auto grid w-full max-w-7xl gap-10 px-4 sm:px-6 md:grid-cols-2 lg:grid-cols-3 lg:px-8">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-emerald-300">Contact Us</p>
                <h4 class="mt-2 text-xl font-bold">Carmen Campus</h4>
                <p class="mt-2 text-sm text-emerald-100">Max Suniel St., Carmen, CDO, Misamis Oriental</p>
                <p class="mt-1 text-sm text-emerald-100">(0917) 376-5105</p>
                <p class="mt-1 text-sm text-emerald-100">(088) 858-5867 to 69</p>
                <p class="mt-1 text-sm text-emerald-100">info.coc@phinmaed.com</p>
            </div>
            <div>
                <h4 class="text-xl font-bold">Puerto Campus</h4>
                <p class="mt-2 text-sm text-emerald-100">Purok 6, Puerto, CDO, Misamis Oriental</p>
                <p class="mt-1 text-sm text-emerald-100">(0916) 131-8900</p>
                <p class="mt-1 text-sm text-emerald-100">(088) 858-5867 to 69</p>
                <p class="mt-1 text-sm text-emerald-100">info.coc@phinmaed.com</p>
            </div>
            <div class="flex flex-col items-start gap-4 md:items-end lg:items-end">
                <img src="<?= htmlspecialchars($basePath) ?>/assets/images/coc-white.png" alt="Cagayan De Oro College" class="h-14 w-auto object-contain">
                <img src="<?= htmlspecialchars($basePath) ?>/assets/images/phinma_white.png" alt="PHINMA Education" class="h-10 w-auto object-contain">
            </div>
        </div>
        <p class="mx-auto mt-8 w-full max-w-7xl px-4 text-xs text-emerald-300 sm:px-6 lg:px-8">&copy; 2026 PHINMA Cagayan De Oro College. All rights reserved.</p>
    </footer>

    <script>
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                }
            });
        }, { threshold: 0.12 });

        document.querySelectorAll('.reveal').forEach((el) => observer.observe(el));
    </script>
</body>
</html>

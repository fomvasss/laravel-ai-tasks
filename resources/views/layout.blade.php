<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI Tasks — @yield('title', 'Dashboard')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' }
        const html = document.documentElement;
        const stored = localStorage.getItem('ai-tasks-theme');
        const cfgTheme = '{{ config('ai-tasks.dashboard.theme', 'system') }}';
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const useDark = stored
            ? stored === 'dark'
            : (cfgTheme === 'dark' || (cfgTheme === 'system' && prefersDark));
        if (useDark) html.classList.replace('light', 'dark');
    </script>
    <style>
        /* pagination active page: match theme */
        nav[aria-label="Pagination Navigation"] span[aria-current="page"] > span {
            background-color: #374151 !important; /* gray-700 */
            color: #fff !important;
            border-color: #374151 !important;
        }
        .dark nav[aria-label="Pagination Navigation"] span[aria-current="page"] > span {
            background-color: #e5e7eb !important; /* gray-200 */
            color: #111827 !important;
            border-color: #e5e7eb !important;
        }
        /* inactive links in dark mode */
        .dark nav[aria-label="Pagination Navigation"] a,
        .dark nav[aria-label="Pagination Navigation"] button {
            background-color: #111827;
            color: #9ca3af;
            border-color: #374151;
        }
        .dark nav[aria-label="Pagination Navigation"] a:hover {
            background-color: #1f2937;
            color: #e5e7eb;
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-950 min-h-screen text-sm text-gray-800 dark:text-gray-200 transition-colors">

<nav class="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 px-6 py-3 flex items-center gap-4">
    <a href="{{ route('ai-tasks.index') }}" class="font-semibold text-gray-900 dark:text-gray-100 text-base tracking-tight">AI Tasks</a>
    <span class="text-gray-400 dark:text-gray-500 text-xs">{{ config('ai-tasks.default') }} &middot; {{ config('app.env') }}</span>
    <div class="ml-auto">
        <button id="theme-toggle" onclick="toggleTheme()"
            class="text-xs px-3 py-1.5 rounded border border-gray-200 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
            <span class="dark:hidden">☾ Dark</span>
            <span class="hidden dark:inline">☀ Light</span>
        </button>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-6 py-6">
    @if(session('ai-tasks-flash'))
        @php([$flashType, $flashText] = session('ai-tasks-flash'))
        <div class="mb-4 px-4 py-3 rounded-lg border text-sm {{ $flashType === 'ok'
            ? 'bg-green-50 dark:bg-green-950/40 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300'
            : 'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300' }}">
            {{ $flashText }}
        </div>
    @endif
    @yield('content')
</main>

<script>
    // Row action menus. Delegated from the document because the runs table replaces its rows
    // on every poll — a handler bound to a button would not survive the first refresh.
    (function () {
        function closeAll() {
            document.querySelectorAll('.dropdown-menu').forEach(m => {
                m.classList.add('hidden');
                m.removeAttribute('style');
            });
        }

        // Going back restores the page from the bfcache with the DOM exactly as it was left —
        // including the menu that was open when its link was followed, still pinned to
        // coordinates from the previous viewport.
        window.addEventListener('pageshow', closeAll);

        document.addEventListener('click', function (e) {
            const toggle = e.target.closest('.dropdown-toggle');

            if (! toggle) {
                if (! e.target.closest('.dropdown-menu')) closeAll();
                return;
            }

            const menu = toggle.parentElement.querySelector('.dropdown-menu');
            const wasOpen = ! menu.classList.contains('hidden');
            closeAll();
            if (wasOpen) return;

            // Fixed, not absolute: the table scrolls inside overflow-x-auto, which clips an
            // absolutely positioned menu instead of letting it overhang the row.
            const rect = toggle.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 4) + 'px';
            menu.style.left = rect.left + 'px';
            menu.classList.remove('hidden');
        });

        document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });
        window.addEventListener('scroll', closeAll, true);
    })();

    function toggleTheme() {
        const html = document.documentElement;
        if (html.classList.contains('dark')) {
            html.classList.replace('dark', 'light');
            localStorage.setItem('ai-tasks-theme', 'light');
        } else {
            html.classList.replace('light', 'dark');
            localStorage.setItem('ai-tasks-theme', 'dark');
        }
    }
</script>

</body>
</html>

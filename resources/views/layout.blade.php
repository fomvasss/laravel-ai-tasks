<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Tasks — @yield('title', 'Dashboard')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { darkMode: 'class' }
        const html = document.documentElement;
        const stored = localStorage.getItem('ai-tasks-theme');
        if (stored === 'dark' || (!stored && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            html.classList.replace('light', 'dark');
        }
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
    @yield('content')
</main>

<script>
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

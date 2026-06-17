<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Tasks — @yield('title', 'Dashboard')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen text-sm text-gray-800">

<nav class="bg-white border-b border-gray-200 px-6 py-3 flex items-center gap-4">
    <a href="{{ route('ai-tasks.index') }}" class="font-semibold text-gray-900 text-base tracking-tight">AI Tasks</a>
    <span class="text-gray-400 text-xs">{{ config('ai.default') }} &middot; {{ config('app.env') }}</span>
</nav>

<main class="max-w-7xl mx-auto px-6 py-6">
    @yield('content')
</main>

</body>
</html>

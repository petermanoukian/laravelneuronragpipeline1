<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'RAG V2')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen text-gray-800">
    <nav class="bg-white shadow-sm mb-8">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="{{ route('public.contents.searchgemini1') }}" class="text-lg font-bold text-gray-800">
                RAG V2
            </a>

            <div class="flex items-center gap-6 text-sm font-medium">
                <a
                    href="{{ route('public.contents.searchgemini1') }}"
                    class="{{ request()->routeIs('public.contents.searchgemini1') ? 'text-blue-600 font-semibold' : 'text-gray-600 hover:text-blue-600' }} transition"
                >
                    Search
                </a>
                <a
                    href="{{ route('public.contents.chat') }}"
                    class="{{ request()->routeIs('public.contents.chat') ? 'text-blue-600 font-semibold' : 'text-gray-600 hover:text-blue-600' }} transition"
                >
                    Chat
                </a>

                <a
                    href="{{ route('public.contents.chat.v2') }}"
                    class="{{ request()->routeIs('public.contents.chat.v2') ? 'text-blue-600 font-semibold' : 'text-gray-600 hover:text-blue-600' }} transition"
                >
                    Chat version 2
                </a>

            </div>
        </div>
    </nav>

    <main class="max-w-5xl mx-auto py-8 px-4">
        @yield('content')
    </main>
</body>
</html>
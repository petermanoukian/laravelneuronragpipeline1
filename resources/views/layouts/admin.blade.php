<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Admin - RAG V2')</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex">
    <!-- Vertical Sidebar Navigation -->
    <aside class="w-64 bg-white shadow-md flex flex-col justify-between">
        <div>
            <div class="p-6 border-b">
                <span class="text-xl font-bold text-gray-800">RAG V2 Admin</span>
            </div>
            <nav class="p-4 space-y-2">
                <a href="{{ route('admin.contents.viewfiles') }}" class="block px-4 py-2 rounded text-gray-700 hover:bg-blue-50 hover:text-blue-600 font-medium">Content</a>
                <a href="{{ route('admin.contents.create') }}" class="block px-4 py-2 rounded text-gray-700 hover:bg-blue-50 hover:text-blue-600 font-medium">Add Content</a>
                <a href="{{ route('admin.contents.files.create') }}" class="block px-4 py-2 rounded text-gray-700 hover:bg-blue-50 hover:text-blue-600 font-medium">Upload Files</a>
            </nav>
        </div>
        <div class="p-4 border-t">
            <div class="text-sm text-gray-600 mb-2 truncate">Logged in as: <strong>{{ Auth::user()->username }}</strong></div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full bg-red-500 hover:bg-red-600 text-white text-sm font-semibold py-2 px-4 rounded transition text-center">
                    Sign Out
                </button>
            </form>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 p-10 overflow-y-auto">
        @yield('content')
    </main>
</body>
</html>
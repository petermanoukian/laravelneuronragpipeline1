@extends('layouts.admin')

@section('title', 'View Content - RAG V2')

@section('content')
<div class="space-y-8">
    <div class="bg-white p-8 rounded-lg shadow-md">
        <h1 class="text-2xl font-bold text-gray-800 mb-2">Content &amp; Files Management</h1>
        <p class="text-gray-600 mb-6">Manage database RAG content records and standalone files in uploads/content/files2.</p>

        @if (session('success'))
            <div class="mb-4 p-4 rounded bg-green-100 border border-green-400 text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-4 p-4 rounded bg-red-100 border border-red-400 text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div class="flex flex-wrap gap-4">
            <a href="{{ route('admin.contents.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition">
                + Add New Content
            </a>
            <a href="{{ route('admin.contents.files.create') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded transition">
                + Upload Standalone Files
            </a>
        </div>
    </div>

    {{-- here perter: DB content records (red border) --}}
    <div class="bg-white p-6 rounded-lg shadow-md border-2 border-red-500">
        <h2 class="text-xl font-bold text-gray-800 mb-4">Database Content Records</h2>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="bg-gray-50 text-left text-xs font-semibold text-gray-600 uppercase">
                        <th class="py-3 px-4">ID</th>
                        <th class="py-3 px-4">Title</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($contents as $item)
                        <tr>
                            <td class="py-3 px-4 text-sm text-gray-700">{{ $item->id }}</td>
                            <td class="py-3 px-4 text-sm font-medium text-gray-900">{{ $item->title }}</td>
                            <td class="py-3 px-4 text-sm text-right">
                                <div class="inline-flex items-center space-x-2">
                                    <a href="{{ route('admin.contents.edit', $item->id) }}" class="bg-amber-500 hover:bg-amber-600 text-white font-semibold py-1 px-3 rounded transition">
                                        Edit
                                    </a>
                                    <form action="{{ route('admin.contents.destroy', $item->id) }}" method="POST" onsubmit="return confirm('Delete content #{{ $item->id }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-semibold py-1 px-3 rounded transition">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-4 px-4 text-center text-sm text-gray-500">No database content records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if (method_exists($contents, 'links'))
            <div class="mt-4">
                {{ $contents->links() }}
            </div>
        @endif
    </div>

    {{-- here perter: standalone non-DB files in uploads/content/files2 (blue border + h1) --}}
    <div class="bg-white p-6 rounded-lg shadow-md border-2 border-blue-500">
        <h1 class="text-2xl font-bold text-blue-900 mb-4">Standalone Files (uploads/content/files2)</h1>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead>
                    <tr class="bg-blue-50 text-left text-xs font-semibold text-blue-900 uppercase">
                        <th class="py-3 px-4">Filename</th>
                        <th class="py-3 px-4">Path</th>
                        <th class="py-3 px-4 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($standaloneFiles ?? [] as $file)
                        <tr>
                            <td class="py-3 px-4 text-sm font-medium text-gray-900">{{ $file['filename'] }}</td>
                            <td class="py-3 px-4 text-sm text-gray-600">{{ $file['relative_path'] }}</td>
                            <td class="py-3 px-4 text-sm text-right">
                                <form action="{{ route('admin.contents.files.destroy', ['filename' => $file['filename']]) }}" method="POST" class="inline" onsubmit="return confirm('Delete file {{ $file['filename'] }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-1 px-3 rounded transition">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="py-4 px-4 text-center text-sm text-gray-500">No standalone files found in uploads/content/files2.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
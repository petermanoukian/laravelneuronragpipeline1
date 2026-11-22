@extends('layouts.public')

@section('title', 'Search - RAG V2')

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Search Content</h1>

    <form method="POST" action="{{ route('public.contents.searchgemini1') }}" class="bg-white p-6 rounded-lg shadow-md mb-6 flex gap-3">
        @csrf
        <input
            type="text"
            name="q"
            value="{{ old('q', $query ?? '') }}"
            placeholder="Enter search query..."
            class="flex-1 border border-gray-300 rounded-lg px-4 py-2 text-gray-800 focus:outline-none focus:ring-2 focus:ring-blue-500"
            autofocus
        >
        <button
            type="submit"
            class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2 rounded-lg transition"
        >
            Search
        </button>
    </form>

    @if($errors->any())
        <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <ul class="list-disc list-inside text-sm">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(!empty($aiAnswer))
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 shadow-sm mb-6">
            <h2 class="text-sm font-bold uppercase tracking-wider text-blue-800 mb-2">Gemini Response</h2>
            <div class="text-gray-800 whitespace-pre-line">{{ $aiAnswer }}</div>
        </div>
    @endif

    @if(!empty($matchedFiles))
        <div class="bg-white p-6 rounded-lg shadow-md mb-6">
            <h2 class="text-sm font-bold uppercase tracking-wider text-gray-700 mb-3">Matched Stored Files</h2>
            <div class="space-y-2">
                @foreach($matchedFiles as $mf)
                    @php
                        $rel = ltrim((string) ($mf['relative_path'] ?? ''), '/');
                        $fileUrl = asset($rel);
                    @endphp
                    <div class="flex items-center justify-between gap-4 border border-gray-200 rounded-lg px-4 py-2.5 text-sm">
                        <div>
                            <span class="font-semibold text-gray-900">{{ $mf['filename'] ?? basename($rel) }}</span>
                            <span class="text-xs text-gray-500 ml-2">({{ strtoupper($mf['extension'] ?? '') }})</span>
                        </div>
                        @if($rel !== '')
                            <a href="{{ $fileUrl }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline font-medium">
                                Open File
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(isset($results))
        <div class="space-y-4">
            @forelse($results as $item)
                @php
                    $imgPath = !empty($item->image_path) ? ltrim((string) $item->image_path, '/') : null;
                    $filePath = !empty($item->file_path) ? ltrim((string) $item->file_path, '/') : null;
                    $pdfPath = !empty($item->pdf_path) ? ltrim((string) $item->pdf_path, '/') : null;
                @endphp
                <article class="bg-white p-6 rounded-lg shadow-md">
                    <h3 class="text-xl font-bold text-gray-900 mb-2">{{ $item->title }}</h3>
                    <p class="text-gray-700 whitespace-pre-line mb-3">{{ $item->content }}</p>

                    @if($imgPath)
                        <div class="mb-4">
                            <img
                                src="{{ asset($imgPath) }}"
                                alt="{{ $item->title }}"
                                class="max-h-64 rounded-lg border border-gray-200 object-contain"
                            >
                        </div>
                    @endif

                    @if($filePath || $pdfPath)
                        <div class="flex flex-wrap gap-3 mb-3 text-sm">
                            @if($filePath)
                                <a
                                    href="{{ asset($filePath) }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1.5 bg-gray-100 hover:bg-gray-200 text-gray-800 border border-gray-300 rounded-md px-3 py-1.5 font-medium transition"
                                >
                                    Attached File: {{ basename($filePath) }}
                                </a>
                            @endif

                            @if($pdfPath)
                                <a
                                    href="{{ asset($pdfPath) }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1.5 bg-red-50 hover:bg-red-100 text-red-800 border border-red-200 rounded-md px-3 py-1.5 font-medium transition"
                                >
                                    PDF: {{ basename($pdfPath) }}
                                </a>
                            @endif
                        </div>
                    @endif

                    @if(!empty($item->keywords))
                        <div class="flex flex-wrap gap-2 mb-3">
                            @foreach(array_filter(array_map('trim', explode(',', $item->keywords))) as $keyword)
                                <span class="bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-1 rounded-full">
                                    {{ $keyword }}
                                </span>
                            @endforeach
                        </div>
                    @endif

                    @if(!empty($item->url_one) || !empty($item->url_two) || !empty($item->url_three))
                        <div class="flex flex-wrap gap-4 text-sm">
                            @foreach([$item->url_one, $item->url_two, $item->url_three] as $url)
                                @if(!empty($url))
                                    <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline">
                                        {{ $url }}
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </article>
            @empty
                <div class="bg-white p-6 rounded-lg shadow-md text-center text-gray-500">
                    No matching content found.
                </div>
            @endforelse
        </div>
    @endif
</div>
@endsection
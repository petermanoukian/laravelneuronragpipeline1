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

    @if(isset($results))
        <div class="space-y-4">
            @forelse($results as $item)
                <article class="bg-white p-6 rounded-lg shadow-md">
                    <h3 class="text-xl font-bold text-gray-900 mb-2">{{ $item->title }}</h3>
                    <p class="text-gray-700 whitespace-pre-line mb-3">{{ $item->content }}</p>

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
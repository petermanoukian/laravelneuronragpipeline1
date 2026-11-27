@extends('layouts.admin')

@section('title', 'Edit Content - RAG V2')

@section('content')
<div class="bg-white p-8 rounded-lg shadow-md border-2 border-red-500">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Edit Content #{{ $content->id }}</h1>
            <p class="text-gray-600 text-sm">Update database RAG content record and optional files.</p>
        </div>
        <a href="{{ route('admin.contents.viewfiles') }}" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded transition">
            Back to List
        </a>
    </div>

    @if ($errors->any())
        <div class="mb-6 p-4 rounded bg-red-100 border border-red-400 text-red-800">
            <ul class="list-disc list-inside text-sm space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form id="edit-content-form" action="{{ route('admin.contents.update', $content->id) }}" method="POST" enctype="multipart/form-data" class="space-y-5">
        @csrf

        <div>
            <label for="title" class="block text-sm font-semibold text-gray-700 mb-1">Title *</label>
            <input type="text" id="title" name="title" value="{{ old('title', $content->title) }}" required
                   class="w-full border border-gray-300 rounded px-3 py-2 text-gray-900 focus:outline-none focus:border-blue-500">
        </div>

        <div>
            <label for="content" class="block text-sm font-semibold text-gray-700 mb-1">Content *</label>
            <textarea id="content" name="content" rows="6" required
                      class="w-full border border-gray-300 rounded px-3 py-2 text-gray-900 focus:outline-none focus:border-blue-500">{{ old('content', $content->content) }}</textarea>
        </div>

        {{-- Interactive Tag-Style Keywords Input --}}
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1" for="keyword-input">Keywords (Type and press Comma or Enter)</label>

            <input type="hidden" id="keywords-hidden" name="keywords" value="{{ old('keywords', $content->keywords) }}">

            <div id="keywords-container"
                 class="border border-gray-300 rounded w-full py-1.5 px-2 text-gray-900 bg-white flex flex-wrap items-center gap-2 focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500 cursor-text min-h-[42px]"
                 onclick="document.getElementById('keyword-input').focus()">

                <input id="keyword-input" type="text"
                       class="flex-1 min-w-[140px] outline-none text-sm py-1 px-1 bg-transparent"
                       placeholder="e.g. laravel, php, sqlite...">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="url_one" class="block text-sm font-semibold text-gray-700 mb-1">URL One</label>
                <input type="url" id="url_one" name="url_one" value="{{ old('url_one', $content->url_one) }}"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-gray-900 focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label for="url_two" class="block text-sm font-semibold text-gray-700 mb-1">URL Two</label>
                <input type="url" id="url_two" name="url_two" value="{{ old('url_two', $content->url_two) }}"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-gray-900 focus:outline-none focus:border-blue-500">
            </div>

            <div>
                <label for="url_three" class="block text-sm font-semibold text-gray-700 mb-1">URL Three</label>
                <input type="url" id="url_three" name="url_three" value="{{ old('url_three', $content->url_three) }}"
                       class="w-full border border-gray-300 rounded px-3 py-2 text-gray-900 focus:outline-none focus:border-blue-500">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label for="file_path" class="block text-sm font-semibold text-gray-700 mb-1">
                    Replace Generic File (PDF, Image, Text, MD, JSON, HTML, DOC, DOCX, RTF, CSV, XLS, XLSX)
                </label>
                @if (!empty($content->file_path))
                    <p class="text-xs text-gray-500 mb-1">Current: {{ $content->file_path }}</p>
                @endif
                <input id="file_path" type="file" name="file_path"
                       accept="application/pdf,image/*,.txt,.md,text/markdown,application/json,text/html,.doc,.docx,.rtf,.csv,.xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,application/rtf,text/rtf"
                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            </div>

            <div>
                <label for="file_path_two" class="block text-sm font-semibold text-gray-700 mb-1">
                    Replace Second Optional File (PDF, Image, Text, MD, JSON, HTML, DOC, DOCX, RTF, CSV, XLS, XLSX)
                </label>
                @if (!empty($content->file_path_two))
                    <p class="text-xs text-gray-500 mb-1">Current: {{ $content->file_path_two }}</p>
                @endif
                <input id="file_path_two" type="file" name="file_path_two"
                       accept="application/pdf,image/*,.txt,.md,text/markdown,application/json,text/html,.doc,.docx,.rtf,.csv,.xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,application/rtf,text/rtf"
                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            </div>

            <div>
                <label for="image_path" class="block text-sm font-semibold text-gray-700 mb-1">Replace Image Only</label>
                @if (!empty($content->image_path))
                    <p class="text-xs text-gray-500 mb-1">Current: {{ $content->image_path }}</p>
                @endif
                <input id="image_path" type="file" name="image_path" accept="image/*"
                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            </div>
        </div>

        <div class="pt-4 flex items-center space-x-3">
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-semibold py-2 px-6 rounded transition">
                Save Changes
            </button>
            <a href="{{ route('admin.contents.viewfiles') }}" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-semibold py-2 px-4 rounded transition">
                Cancel
            </a>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('keywords-container');
        const input = document.getElementById('keyword-input');
        const hiddenInput = document.getElementById('keywords-hidden');
        const form = document.getElementById('edit-content-form');

        let tags = [];

        if (hiddenInput.value.trim() !== '') {
            hiddenInput.value.split(',').forEach(item => addTag(item, false));
            renderTags();
        }

        function syncHiddenInput() {
            hiddenInput.value = tags.join(', ');
        }

        function addTag(rawValue, shouldRender = true) {
            const clean = rawValue.replace(/,/g, '').trim();
            if (clean !== '' && !tags.includes(clean)) {
                tags.push(clean);
                if (shouldRender) renderTags();
            }
        }

        function removeTag(index) {
            tags.splice(index, 1);
            renderTags();
        }

        function renderTags() {
            container.querySelectorAll('.keyword-pill').forEach(pill => pill.remove());

            tags.forEach((tag, index) => {
                const pill = document.createElement('span');
                pill.className = 'keyword-pill inline-flex items-center bg-blue-100 text-blue-800 text-xs font-semibold px-2.5 py-1 rounded-full';

                const text = document.createElement('span');
                text.textContent = tag;

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.innerHTML = '&times;';
                removeBtn.className = 'ml-1.5 text-blue-600 hover:text-blue-900 font-bold focus:outline-none text-sm leading-none';
                removeBtn.onclick = (e) => {
                    e.stopPropagation();
                    removeTag(index);
                };

                pill.appendChild(text);
                pill.appendChild(removeBtn);
                container.insertBefore(pill, input);
            });

            syncHiddenInput();
        }

        input.addEventListener('keydown', (e) => {
            if (e.key === ',' || e.key === 'Enter') {
                e.preventDefault();
                addTag(input.value);
                input.value = '';
            } else if (e.key === 'Backspace' && input.value === '' && tags.length > 0) {
                removeTag(tags.length - 1);
            }
        });

        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text');
            pasted.split(',').forEach(part => addTag(part, false));
            renderTags();
            input.value = '';
        });

        input.addEventListener('blur', () => {
            if (input.value.trim() !== '') {
                addTag(input.value);
                input.value = '';
            }
        });

        form.addEventListener('submit', () => {
            if (input.value.trim() !== '') {
                addTag(input.value);
                input.value = '';
            }
        });
    });
</script>
@endsection

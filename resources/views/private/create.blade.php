@extends('layouts.admin')

@section('title', 'Add Content - RAG V2')

@section('content')
<div class="bg-white p-8 rounded-lg shadow-md max-w-3xl">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">Add New RAG Content</h1>

    @if($errors->any())
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(session('success'))
        <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
            <strong class="font-bold">Success!</strong>
            <span class="block sm:inline">{{ session('success') }}</span>
        </div>
    @endif

    <form id="content-form" action="{{ route('admin.contents.add') }}" method="POST" enctype="multipart/form-data">
        @csrf
        <div class="mb-4">
            <label class="block text-gray-700 text-sm font-bold mb-2" for="title">Title</label>
            <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                   id="title" type="text" name="title" value="{{ old('title') }}" required>
        </div>

        <div class="mb-4">
            <label class="block text-gray-700 text-sm font-bold mb-2" for="content">Content</label>
            <textarea class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline h-32" 
                      id="content" name="content" required>{{ old('content') }}</textarea>
        </div>

        {{-- Interactive Tag-Style Keywords Input --}}
        <div class="mb-4">
            <label class="block text-gray-700 text-sm font-bold mb-2" for="keyword-input">Keywords (Type and press Comma or Enter)</label>
            
            {{-- Hidden input that sends the final comma-separated string to Laravel --}}
            <input type="hidden" id="keywords-hidden" name="keywords" value="{{ old('keywords') }}">

            {{-- Visual Tag Container --}}
            <div id="keywords-container" 
                 class="shadow border rounded w-full py-1.5 px-2 text-gray-700 bg-white flex flex-wrap items-center gap-2 focus-within:ring-2 focus-within:ring-blue-500 focus-within:border-blue-500 cursor-text min-h-[42px]"
                 onclick="document.getElementById('keyword-input').focus()">
                
                {{-- Pills get injected here via JS --}}
                <input id="keyword-input" type="text" 
                       class="flex-1 min-w-[140px] outline-none text-sm py-1 px-1 bg-transparent" 
                       placeholder="e.g. laravel, php, sqlite...">
            </div>
        </div>

        <div class="grid grid-cols-3 gap-4 mb-4">
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="url_one">URL One</label>
                <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                       id="url_one" type="url" name="url_one" value="{{ old('url_one') }}">
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="url_two">URL Two</label>
                <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                       id="url_two" type="url" name="url_two" value="{{ old('url_two') }}">
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="url_three">URL Three</label>
                <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                       id="url_three" type="url" name="url_three" value="{{ old('url_three') }}">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="file_path">Generic File (PDF, Image, Text, JSON, HTML, DOC, DOCX)</label>
                <input class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" 
                       id="file_path" type="file" name="file_path" 
                       accept="application/pdf,image/*,.txt,application/json,text/html,.doc,.docx">
            </div>
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="image_path">Image Only</label>
                <input class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" 
                       id="image_path" type="file" name="image_path" accept="image/*">
            </div>
        </div>

        <div class="flex items-center justify-end">
            <button class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded focus:outline-none focus:shadow-outline transition" type="submit">
                Save Content
            </button>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('keywords-container');
        const input = document.getElementById('keyword-input');
        const hiddenInput = document.getElementById('keywords-hidden');
        const form = document.getElementById('content-form');

        let tags = [];

        // Restore existing tags if old('keywords') is present
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
            // Remove existing pill elements before re-rendering
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

        // Handle pasting comma-separated lists directly
        input.addEventListener('paste', (e) => {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text');
            pasted.split(',').forEach(part => addTag(part, false));
            renderTags();
            input.value = '';
        });

        // Commit any leftover text in the input when leaving the field or submitting the form
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
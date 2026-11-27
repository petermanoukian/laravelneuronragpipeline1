@extends('layouts.admin')

@section('title', 'Upload Files - RAG V2')

@section('content')
<div class="bg-white p-8 rounded-lg shadow-md max-w-2xl">
    <h1 class="text-2xl font-bold text-gray-800 mb-2">Upload Standalone Files</h1>
    <p class="text-xs text-gray-500 mb-6">Files are saved directly to <code>public/uploads/content/files2</code> without database records.</p>

    @if($errors->any())
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <ul class="list-disc list-inside text-sm">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(session('success'))
        <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded" role="alert">
            <strong class="font-bold">Success!</strong>
            <span class="block sm:inline">{{ session('success') }}</span>

            @if(is_array(session('uploaded_files')) && count(session('uploaded_files')) > 0)
                <ul class="mt-2 list-disc list-inside text-xs text-green-900">
                    @foreach(session('uploaded_files') as $uploadedPath)
                        <li>{{ $uploadedPath }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    <form action="{{ route('admin.contents.files.store') }}" method="POST" enctype="multipart/form-data">
        @csrf

        <div class="mb-6">
            <label class="block text-gray-700 text-sm font-bold mb-2" for="files">
                Select Files (PDF, TXT, MD, RTF, CSV, XLS, XLSX, DOC, DOCX)
            </label>
            <input
                class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                id="files"
                type="file"
                name="files[]"
                multiple
                required
                accept="application/pdf,.txt,.md,text/markdown,.rtf,application/rtf,text/rtf,.csv,text/csv,.xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,.doc,.docx"
            >
        </div>

        <div class="flex items-center justify-end">
            <button
                class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-6 rounded focus:outline-none focus:shadow-outline transition"
                type="submit"
            >
                Upload Files
            </button>
        </div>
    </form>
</div>
@endsection
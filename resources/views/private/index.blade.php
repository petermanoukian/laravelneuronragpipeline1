@extends('layouts.admin')

@section('title', 'Admin Dashboard - RAG V2')

@section('content')
<div class="bg-white p-8 rounded-lg shadow-md">
    <h1 class="text-2xl font-bold text-gray-800 mb-2">Admin Dashboard</h1>
    <p class="text-gray-600 mb-6">Welcome back! Manage your multi-modal RAG data ingestion pipeline.</p>
    <div class="flex space-x-4">
        <a href="{{ route('admin.contents.create') }}" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded transition">
            + Add New Content
        </a>
    </div>
</div>
@endsection
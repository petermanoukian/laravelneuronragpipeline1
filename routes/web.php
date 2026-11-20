<?php
use App\Http\Controllers\Public\ContentController;
use Illuminate\Support\Facades\Route;

Route::get('/', [ContentController::class, 'searchgemini1'])->name('home');

require __DIR__.'/auth/auth.php';

require __DIR__.'/admin/admin.php';

require __DIR__ . '/public/public.php';

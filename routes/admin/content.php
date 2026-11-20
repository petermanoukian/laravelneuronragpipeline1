<?php

use App\Http\Controllers\Admin\ContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('contents')->name('contents.')->group(function () {
    // GET: /dashboard/contents/create -> name: admin.contents.create
    Route::get('/create', [ContentController::class, 'create'])->name('create');

    // POST: /dashboard/contents/add -> name: admin.contents.add
    Route::post('/add', [ContentController::class, 'add'])->name('add');
});
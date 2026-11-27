<?php

use App\Http\Controllers\Admin\ContentController;
use Illuminate\Support\Facades\Route;

Route::prefix('contents')->name('contents.')->group(function () {

    Route::get('/viewfiles', [ContentController::class, 'index'])->name('viewfiles');

    // GET: /dashboard/contents/create -> name: admin.contents.create
    Route::get('/create', [ContentController::class, 'create'])->name('create');

    // POST: /dashboard/contents/add -> name: admin.contents.add
    Route::post('/add', [ContentController::class, 'add'])->name('add');

    // here perter: explicit edit, update, and delete routes for DB content records
    Route::get('/edit/{id}', [ContentController::class, 'edit'])->name('edit');
    Route::post('/update/{id}', [ContentController::class, 'update'])->name('update');
    Route::delete('/delete/{id}', [ContentController::class, 'destroy'])->name('destroy');

    // here perter: standalone file-only upload routes (no database)
    Route::get('/createfiles', [ContentController::class, 'createfile'])->name('files.create');
    Route::post('/addfiles', [ContentController::class, 'filesStore'])->name('files.store');

    // here perter: delete standalone files from uploads/content/files2
    Route::delete('/deletefiles/{filename}', [ContentController::class, 'deleteStandaloneFile'])->name('files.destroy');
});
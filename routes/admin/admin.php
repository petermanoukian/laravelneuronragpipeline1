<?php

use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;

/**
 * Parent Admin/Dashboard Routes.
 */

// We protect these serious routes with 'auth' middleware and define the '/dashboard' prefix,
// and we include the content routes directly inside this group so they inherit everything.
Route::middleware(['auth'])->prefix('dashboard')->name('admin.')->group(function () {
    
    // URI: /dashboard
    // Route Name: admin.index
    Route::get('/', [AdminController::class, 'index'])->name('index');

    // Include the content routes directly inside the protected dashboard group
    require __DIR__ . '/content.php';
});
<?php

use Illuminate\Support\Facades\Route;

Route::name('public.')->prefix('content')->group(function () {
    require __DIR__ . '/content.php';
});
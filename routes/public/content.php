<?php

use App\Http\Controllers\Public\ContentChatV2Controller;
use App\Http\Controllers\Public\ContentController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '/searchgemini1', [ContentController::class, 'searchgemini1'])
    ->name('contents.searchgemini1');

Route::match(['get', 'post'], '/chat', [ContentController::class, 'chat'])
    ->name('contents.chat');

Route::match(['get', 'post'], '/chat-v2', [ContentChatV2Controller::class, 'index'])
    ->name('contents.chat.v2');
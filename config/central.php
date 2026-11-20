<?php

return [
    'gemini_key'            => env('GEMINI_API_KEY'),
    'access_key'            => env('API_ACCESS_KEY'),
    'default_model'         => env('GEMINI_DEFAULT_MODEL', 'gemini-1.5-pro'),
    'chat_model'            => env('GEMINI_CHAT_MODEL', 'gemini-3.8-flash'),
    'chat_fallback_models'  => env('GEMINI_CHAT_FALLBACK_MODELS', 'gemini-3.5-flash-lite,gemini-1.5-pro'),
];
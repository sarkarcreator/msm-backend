<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
    'api' => url('/api/health'),
]));

Route::get('/login', fn () => response()->json([
    'message' => 'Unauthenticated. Use POST /api/login.',
], 401))->name('login');

Route::fallback(fn () => response()->json([
    'message' => 'Not Found. Use the /api endpoints for this backend.',
], 404));

<?php

use App\Http\Controllers\InquiryController;
use Illuminate\Support\Facades\Route;

// GET / — api middleware (no session) → Nginx RAM cache + Cloudflare edge cache
Route::middleware('api')->get('/', fn () => view('home'));

// CSRF token endpoint — session required, fetched by Alpine.js before form submit
Route::get('/api/csrf', fn () => response()->json(['token' => csrf_token()]));

// Form submission — web middleware (CSRF validation, session)
Route::post('/inquiry', [InquiryController::class, 'store'])->name('inquiry.store');

// Benchmark endpoint
Route::middleware('api')->get('/ping', fn () => response()->json([
    'status'  => 'ok',
    'server'  => 'octane+frankenphp',
    'workers' => 4,
]));

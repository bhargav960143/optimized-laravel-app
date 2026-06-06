<?php

use App\Http\Controllers\InquiryController;
use Illuminate\Support\Facades\Route;

// GET / — no session middleware → no cookie → Nginx RAM cache can serve it
Route::middleware('api')->get('/', fn () => view('home'));

// CSRF token endpoint — session required, called by Alpine.js before form submit
Route::get('/api/csrf', fn () => response()->json(['token' => csrf_token()]));

// Form submission — full web middleware (CSRF validation, session, redirect)
Route::post('/inquiry', [InquiryController::class, 'store'])->name('inquiry.store');

// Benchmark endpoint
Route::middleware('api')->get('/ping', fn () => response()->json([
    'status'  => 'ok',
    'server'  => 'octane+swoole',
    'workers' => 4,
]));

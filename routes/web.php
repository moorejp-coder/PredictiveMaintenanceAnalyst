<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AiFeedbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OverrideController;
use App\Http\Middleware\RequireOverrideLogPassword;
use App\Support\CanonicalUrl;
use Illuminate\Support\Facades\Route;

// Sign in. There is no sign-up page. The judge account comes from JudgeSeeder.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// The dashboard and every route it calls need a signed-in user. The pages
// about AI use, the FAQ, Privacy and Terms stay public below. The readings
// poll now starts the session, because the login check reads it.
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::get('/api/readings', [DashboardController::class, 'readings']);
    // Two small AI calls, run only when a visitor presses "Explain this reading".
    // Rate limited so a script cannot run up the AI bill.
    Route::get('/api/diagnosis', [AiController::class, 'diagnosis'])
        ->middleware('throttle:30,1');
    Route::get('/api/work-order', [AiController::class, 'workOrder'])
        ->middleware('throttle:30,1');
    // Override log. An operator who keeps a machine running after the High
    // "Stop the machine" warning records who, why and which reading. The write
    // uses the session and CSRF check. The review page needs its own password.
    Route::post('/api/overrides', [OverrideController::class, 'store'])
        ->middleware('throttle:10,1');
    // A user rates an AI answer "helpful" or "wrong". Fixed choices only, no free text.
    Route::post('/api/ai-feedback', [AiFeedbackController::class, 'store'])
        ->middleware('throttle:20,1');
});
Route::get('/overrides', [OverrideController::class, 'index'])
    ->middleware(RequireOverrideLogPassword::class);
Route::view('/ai', 'ai');
Route::view('/privacy', 'privacy');
Route::view('/terms', 'terms');
Route::view('/faq', 'faq');

Route::get('/sitemap.xml', function () {
    // The dashboard at / needs a sign-in, so it is not listed.
    $paths = ['/ai', '/faq', '/privacy', '/terms'];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    foreach ($paths as $path) {
        $xml .= '  <url><loc>'.e(CanonicalUrl::for($path)).'</loc></url>'."\n";
    }
    $xml .= '</urlset>'."\n";

    return response($xml, 200, ['Content-Type' => 'application/xml']);
});

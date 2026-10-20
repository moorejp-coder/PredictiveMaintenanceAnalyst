<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AiFeedbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OverrideController;
use App\Http\Middleware\RequireOverrideLogPassword;
use App\Support\CanonicalUrl;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', [DashboardController::class, 'index']);
// The dashboard polls this route every 1.2 seconds. It reads no session or
// cookie, so skip that middleware and avoid a session file write per poll.
$stateless = [
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
];
Route::get('/api/readings', [DashboardController::class, 'readings'])
    ->withoutMiddleware($stateless);
// Two small AI calls, run only when a visitor presses "Explain this reading".
// Rate limited so a script cannot run up the AI bill.
Route::get('/api/diagnosis', [AiController::class, 'diagnosis'])
    ->withoutMiddleware($stateless)
    ->middleware('throttle:30,1');
Route::get('/api/work-order', [AiController::class, 'workOrder'])
    ->withoutMiddleware($stateless)
    ->middleware('throttle:30,1');
// Override log. An operator who keeps a machine running after the High
// "Stop the machine" warning records who, why and which reading. The write
// uses the normal session and CSRF check. The review page needs a password.
Route::post('/api/overrides', [OverrideController::class, 'store'])
    ->middleware('throttle:10,1');
Route::get('/overrides', [OverrideController::class, 'index'])
    ->middleware(RequireOverrideLogPassword::class);
// A visitor rates an AI answer "helpful" or "wrong". Fixed choices only, no free text.
Route::post('/api/ai-feedback', [AiFeedbackController::class, 'store'])
    ->middleware('throttle:20,1');
Route::view('/ai', 'ai');
Route::view('/privacy', 'privacy');
Route::view('/terms', 'terms');
Route::view('/faq', 'faq');

Route::get('/sitemap.xml', function () {
    $paths = ['/', '/ai', '/faq', '/privacy', '/terms'];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    foreach ($paths as $path) {
        $xml .= '  <url><loc>'.e(CanonicalUrl::for($path)).'</loc></url>'."\n";
    }
    $xml .= '</urlset>'."\n";

    return response($xml, 200, ['Content-Type' => 'application/xml']);
});

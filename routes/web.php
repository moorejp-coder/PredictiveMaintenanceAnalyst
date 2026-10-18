<?php

use App\Http\Controllers\DashboardController;
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
Route::get('/api/readings', [DashboardController::class, 'readings'])
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ]);
Route::view('/privacy', 'privacy');
Route::view('/terms', 'terms');
Route::view('/faq', 'faq');

Route::get('/sitemap.xml', function () {
    $paths = ['/', '/faq', '/privacy', '/terms'];

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    foreach ($paths as $path) {
        $xml .= '  <url><loc>'.e(CanonicalUrl::for($path)).'</loc></url>'."\n";
    }
    $xml .= '</urlset>'."\n";

    return response($xml, 200, ['Content-Type' => 'application/xml']);
});

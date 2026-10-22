<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AiFeedbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\JudgePasswordController;
use App\Http\Controllers\OverrideController;
use App\Http\Middleware\EnsureLoginEnabled;
use App\Http\Middleware\RequireInviteAdminPassword;
use App\Http\Middleware\RequireLogin;
use App\Http\Middleware\RequireOverrideLogPassword;
use App\Support\CanonicalUrl;
use Illuminate\Support\Facades\Route;

// Sign in. Built but switched off until REQUIRE_LOGIN=true (see
// config/prism.php). With it off, /login goes back to / and the POST routes
// return 404. There is no sign-up page. Judge accounts come from the invite
// page below. JudgeSeeder can add one fixed account as a backup.
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Judge invites. The entrant types a judge's email on /admin/invites (its own
// password). The judge gets an email with a link to /set-password/{token},
// chooses a password, then signs in at /login. All of it is 404 while
// REQUIRE_LOGIN is off.
Route::middleware(EnsureLoginEnabled::class)->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/set-password/{token}', [JudgePasswordController::class, 'show'])->name('invite.show');
        Route::post('/set-password', [JudgePasswordController::class, 'update'])
            ->middleware('throttle:10,1')->name('invite.update');
    });
    Route::middleware(RequireInviteAdminPassword::class)->group(function () {
        Route::get('/admin/invites', [InviteController::class, 'index']);
        Route::post('/admin/invites', [InviteController::class, 'store'])->middleware('throttle:10,1');
    });
});

// With REQUIRE_LOGIN on, the dashboard and every route it calls need a
// signed-in user. With it off they are public. The pages about AI use, the
// FAQ, Privacy and Terms are always public. The routes start the session
// either way, because the gate reads it.
Route::middleware(RequireLogin::class)->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::get('/api/readings', [DashboardController::class, 'readings']);
    // Two small AI calls, run only when a visitor presses "Explain this reading".
    // Rate limited so a script cannot run up the AI bill.
    // The diagnosis route can run up to four model rounds, so its limit is lower.
    Route::get('/api/diagnosis', [AiController::class, 'diagnosis'])
        ->middleware('throttle:10,1');
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
    // With the sign-in on, the dashboard at / is not public, so it is not listed.
    $paths = ['/ai', '/faq', '/privacy', '/terms'];
    if (! config('prism.require_login')) {
        array_unshift($paths, '/');
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    foreach ($paths as $path) {
        $xml .= '  <url><loc>'.e(CanonicalUrl::for($path)).'</loc></url>'."\n";
    }
    $xml .= '</urlset>'."\n";

    return response($xml, 200, ['Content-Type' => 'application/xml']);
});

<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AiFeedbackController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\JudgePasswordController;
use App\Http\Controllers\MyOverrideController;
use App\Http\Controllers\OverrideController;
use App\Http\Middleware\BlockInDemo;
use App\Http\Middleware\CapAiCalls;
use App\Http\Middleware\EnsureLoginEnabled;
use App\Http\Middleware\RejectAbuse;
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
    Route::post('/login', [AuthController::class, 'login'])->middleware(['throttle:5,1', RejectAbuse::class]);
});
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Judge Demo. A link that opens the dashboard read-only, with no account and
// no password. It works more than once. The override log and AI ratings are
// blocked for a demo session (BlockInDemo).
Route::get('/demo', [DemoController::class, 'start'])->name('demo');

// Judge invites. The entrant types a judge's email on /admin/invites (its own
// password). The judge gets an email with a link to /set-password/{token},
// chooses a password, then signs in at /login. All of it is 404 while
// REQUIRE_LOGIN is off.
Route::middleware(EnsureLoginEnabled::class)->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/set-password/{token}', [JudgePasswordController::class, 'show'])
            ->middleware(['throttle:20,1', RejectAbuse::class])->name('invite.show');
        Route::post('/set-password', [JudgePasswordController::class, 'update'])
            ->middleware(['throttle:5,1', RejectAbuse::class])->name('invite.update');
    });
    // The signed-in user's own page: sign out of every other browser.
    Route::middleware('auth')->group(function () {
        Route::get('/account', [AccountController::class, 'show'])->name('account');
        Route::post('/logout-everywhere', [AccountController::class, 'logoutEverywhere'])
            ->middleware(['throttle:5,1', RejectAbuse::class]);
        // A user's own override entries. Each route that names an entry checks
        // the owner in OverrideLogPolicy and answers 403 for anyone else. The
        // auth middleware keeps out guests and demo sessions.
        Route::get('/api/overrides', [MyOverrideController::class, 'index'])->middleware('throttle:60,1,my-overrides');
        Route::get('/api/overrides/{overrideLog}', [MyOverrideController::class, 'show'])
            ->whereUuid('overrideLog')->middleware('throttle:60,1,my-overrides');
        Route::put('/api/overrides/{overrideLog}', [MyOverrideController::class, 'update'])
            ->whereUuid('overrideLog')->middleware(['throttle:20,1,my-overrides-write', RejectAbuse::class]);
        // Example of the reusable gate (AuthorizeResource): it asks OverrideLogPolicy
        // for the 'delete' ability on the entry in the URL, answers 401 or 403, and logs a
        // refused request. The controller keeps its own check as a second layer.
        Route::delete('/api/overrides/{overrideLog}', [MyOverrideController::class, 'destroy'])
            ->whereUuid('overrideLog')
            ->middleware(['throttle:20,1,my-overrides-write', 'authorize.resource:delete,overrideLog']);
    });
    Route::middleware(RequireInviteAdminPassword::class)->group(function () {
        Route::get('/admin/invites', [InviteController::class, 'index']);
        Route::post('/admin/invites', [InviteController::class, 'store'])->middleware(['throttle:10,1', RejectAbuse::class]);
    });
});

// With REQUIRE_LOGIN on, the dashboard and every route it calls need a
// signed-in user. With it off they are public. The pages about AI use, the
// FAQ, Privacy and Terms are always public. The routes start the session
// either way, because the gate reads it.
Route::middleware(RequireLogin::class)->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    Route::get('/api/readings', [DashboardController::class, 'readings'])->middleware([
        // The prefix gives this route its own counter. Without one, every
        // throttle route shares a single counter per signed-in user, and the
        // dashboard's polling (one request every 1.2 seconds) would use up the
        // limits of the override and AI routes.
        'throttle:120,1,readings',
        RejectAbuse::class,
    ]);
    // Two small AI calls, run only when a visitor presses "Explain this reading".
    // Rate limited so a script cannot run up the AI bill.
    // The diagnosis route can run up to four model rounds, so its limit is lower.
    Route::get('/api/diagnosis', [AiController::class, 'diagnosis'])
        ->middleware(['throttle:10,1', CapAiCalls::class, RejectAbuse::class]);
    Route::get('/api/work-order', [AiController::class, 'workOrder'])
        ->middleware(['throttle:30,1', CapAiCalls::class, RejectAbuse::class]);
    // Override log. An operator who keeps a machine running after the High
    // "Stop the machine" warning records who, why and which reading. The write
    // uses the session and CSRF check. The review page needs its own password.
    Route::post('/api/overrides', [OverrideController::class, 'store'])
        ->middleware([BlockInDemo::class, 'throttle:10,1', RejectAbuse::class]);
    // A user rates an AI answer "helpful" or "wrong". Fixed choices only, no free text.
    Route::post('/api/ai-feedback', [AiFeedbackController::class, 'store'])
        ->middleware([BlockInDemo::class, 'throttle:20,1', RejectAbuse::class]);
});
Route::get('/overrides', [OverrideController::class, 'index'])
    ->middleware(RequireOverrideLogPassword::class);
// Public. Reports only ok or unavailable, so a monitor can poll it.
Route::get('/health', HealthController::class);
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

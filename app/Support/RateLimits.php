<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Every request limit in PRISM, by kind of route. Routes use them as
 * throttle:<name> in routes/web.php. Each route counts on its own, so the
 * dashboard's polling never uses up the limit of another route.
 *
 * Over the limit, Laravel answers 429 Too Many Requests with a Retry-After
 * header (seconds to wait) and X-RateLimit-Limit and X-RateLimit-Remaining.
 */
final class RateLimits
{
    /** Sign-in and other forms that take a password or a one-time token, per IP address. */
    public const AUTH_PER_MINUTE = 5;

    /** Opening an emailed one-time link (set password, unlock account), per IP address. */
    public const AUTH_LINK_PER_MINUTE = 10;

    /** Each judge invite sends an email (Amazon SES), so: per admin, per hour. */
    public const EMAILS_PER_HOUR = 10;

    /**
     * Setting a password asks the Have I Been Pwned range API whether it was
     * in a breach, so these forms also have an hourly cap per IP address.
     */
    public const PASSWORD_SET_PER_HOUR = 20;

    /** Pages that read up to 200 log rows plus counts (/overrides, /admin/invites), per user. */
    public const HEAVY_READ_PER_MINUTE = 30;

    /**
     * Reading data, per user. The dashboard asks for a reading every 1.2
     * seconds (public/js/dashboard.js), about 50 a minute, so 100 leaves room
     * for a second open tab.
     */
    public const READ_PER_MINUTE = 100;

    /** Creating, changing or deleting data, per user. */
    public const WRITE_PER_MINUTE = 20;

    /**
     * File uploads, per user. PRISM has no upload route today. A new one
     * uses throttle:uploads.
     */
    public const UPLOAD_PER_MINUTE = 5;

    /**
     * The two AI routes cost money per call. One press of "Explain this
     * reading" calls both, diagnosis then work order, so they share the same
     * numbers. The diagnosis runs up to four model rounds. The work order
     * reuses the cached diagnosis and adds one short call. CapAiCalls also
     * applies the dollar budgets (AiBudget) and a daily site-wide count.
     */
    public const AI_PER_MINUTE = 10;

    public const AI_PER_HOUR = 60;

    public static function register(): void
    {
        RateLimiter::for('auth', fn (Request $request): Limit => Limit::perMinute(self::AUTH_PER_MINUTE)->by(self::perAddress($request)));
        RateLimiter::for('auth-link', fn (Request $request): Limit => Limit::perMinute(self::AUTH_LINK_PER_MINUTE)->by(self::perAddress($request)));
        RateLimiter::for('invites', fn (Request $request): Limit => Limit::perHour(self::EMAILS_PER_HOUR)->by(self::perUser($request)));
        // Two windows need two keys, or both limits would share one counter.
        RateLimiter::for('password-set', fn (Request $request): array => [
            Limit::perMinute(self::AUTH_PER_MINUTE)->by(self::perAddress($request).'|minute'),
            Limit::perHour(self::PASSWORD_SET_PER_HOUR)->by(self::perAddress($request).'|hour'),
        ]);
        RateLimiter::for('heavy-read', fn (Request $request): Limit => Limit::perMinute(self::HEAVY_READ_PER_MINUTE)->by(self::perUser($request)));
        RateLimiter::for('api-read', fn (Request $request): Limit => Limit::perMinute(self::READ_PER_MINUTE)->by(self::perUser($request)));
        RateLimiter::for('api-write', fn (Request $request): Limit => Limit::perMinute(self::WRITE_PER_MINUTE)->by(self::perUser($request)));
        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(self::UPLOAD_PER_MINUTE)->by(self::perUser($request)));
        foreach (['ai-diagnosis', 'ai-work-order'] as $name) {
            RateLimiter::for($name, fn (Request $request): array => [
                Limit::perMinute(self::AI_PER_MINUTE)->by(self::perUser($request).'|minute'),
                Limit::perHour(self::AI_PER_HOUR)->by(self::perUser($request).'|hour'),
            ]);
        }
    }

    /** One counter per route and IP address. */
    private static function perAddress(Request $request): string
    {
        return self::route($request).'|ip:'.$request->ip();
    }

    /**
     * One counter per route and signed-in account. A visitor with no account
     * (the Judge Demo, or a local copy with the sign-in off) counts by IP address.
     */
    private static function perUser(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return self::route($request).'|'.(is_scalar($id) ? 'user:'.$id : 'ip:'.$request->ip());
    }

    /** The method and route pattern, so /api/overrides/{overrideLog} is one route whatever the id. */
    private static function route(Request $request): string
    {
        return $request->method().' '.($request->route()?->uri() ?? $request->path());
    }
}

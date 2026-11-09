<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The security audit trail. One call writes one JSON line to the "audit" log
 * channel (config/logging.php), apart from the application log.
 *
 * Every line holds the same fields, so a line can be searched without knowing
 * which code wrote it:
 *   event    what happened, such as auth.login.failed
 *   outcome  success, failure, denied or alert
 *   at       UTC time, to the millisecond
 *   source   web, or console for artisan commands and seeders
 *   actor    the signed-in account (user_id) and whether it is an admin
 *   client   IP address and browser type (User-Agent) of the request
 *   request  method, path without query string, route name and the error
 *            reference that also appears in the application log
 *   detail   fields the caller adds, such as the target account
 *
 * Never put passwords, tokens, session ids, request bodies, query strings or a
 * plain email address in detail. An email goes in as LogId::email(), the keyed
 * one-way code the other log lines already use.
 *
 * A failure to write the line is reported to the application log and never
 * changes the answer the visitor gets.
 */
final class AuditLog
{
    public const SUCCESS = 'success';

    public const FAILURE = 'failure';

    public const DENIED = 'denied';

    public const ALERT = 'alert';

    /** Request attribute: this request's denial is already in the audit log. */
    public const DENIAL_LOGGED = 'audit.denial_logged';

    /** Request attribute: a password write in this request is not a password change by a person. */
    public const SKIP_PASSWORD_EVENT = 'audit.skip_password_event';

    private const MAX_AGENT_LENGTH = 255;

    private const MAX_PATH_LENGTH = 200;

    /**
     * @param  array<string, mixed>  $detail
     */
    public static function record(string $event, array $detail = [], string $outcome = self::SUCCESS, ?Request $request = null): void
    {
        try {
            $request ??= self::currentRequest();
            Log::channel('audit')->log(
                $outcome === self::SUCCESS ? 'info' : 'warning',
                $event,
                self::fields($event, $outcome, $detail, $request),
            );
        } catch (Throwable $e) {
            try {
                Log::error('audit_log_failed', ['event' => $event, 'class' => $e::class]);
            } catch (Throwable) {
                // Nothing left to try. The request goes on.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private static function fields(string $event, string $outcome, array $detail, ?Request $request): array
    {
        $user = self::user($request);

        return [
            'event' => $event,
            'outcome' => $outcome,
            'at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'source' => $request === null ? 'console' : 'web',
            'actor' => [
                'user_id' => $user?->getAuthIdentifier(),
                'admin' => $user !== null && $user->isAdmin(),
            ],
            'client' => [
                'ip' => $request?->ip(),
                'user_agent' => self::agent($request),
            ],
            'request' => $request === null ? null : [
                'method' => $request->method(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, self::MAX_PATH_LENGTH),
                'route' => $request->route()?->getName() ?? $request->route()?->uri(),
                'reference' => ErrorReference::for($request),
            ],
            'detail' => $detail,
        ];
    }

    /**
     * The request this line belongs to. An artisan command has none: Laravel
     * still binds a stand-in request there, but it never reaches a route.
     */
    private static function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return app()->runningInConsole() && $request->route() === null ? null : $request;
    }

    /** The signed-in account, or null for a guest, a request with no session yet, or a console run. */
    private static function user(?Request $request): ?User
    {
        if ($request === null) {
            return null;
        }

        try {
            $user = $request->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    /**
     * The browser type the client sent. The client controls it, so control
     * characters and invalid bytes go, and it is cut to 255 characters.
     */
    private static function agent(?Request $request): ?string
    {
        $agent = $request?->headers->get('User-Agent');
        if (! is_string($agent) || $agent === '') {
            return null;
        }

        $clean = preg_replace('/\p{C}+/u', ' ', mb_scrub($agent));

        return mb_substr(trim($clean ?? ''), 0, self::MAX_AGENT_LENGTH);
    }
}

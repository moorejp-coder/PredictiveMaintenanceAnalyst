<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One reusable gate for any route that names a specific resource. Alias:
 * authorize.resource (bootstrap/app.php).
 *
 *   ->middleware('authorize.resource:<check>,<routeParam>')
 *
 * 1. No signed-in user: logs a notice and throws AuthenticationException.
 *    A JSON or /api/ request gets 401. A page request is sent to /login.
 * 2. Signed in, but the check says no: logs a warning and answers 403.
 * 3. Otherwise the request goes on.
 *
 * <check> is one of two things:
 * - A function you registered with AuthorizeResource::define(). It gets the
 *   user, the resource (the route parameter, already loaded by route model
 *   binding) and the request, and returns true to allow.
 * - Otherwise a Policy ability on the model, such as "delete".
 * A name with no function and no Policy denies the request (fails closed).
 *
 * Route model binding runs first, so a missing id is 404 before this runs.
 * Keep a check in the controller too (Gate::authorize) as a second layer.
 * Logs never hold request bodies, query strings or tokens.
 */
final class AuthorizeResource
{
    /** @var array<string, Closure(User, mixed, Request): bool> */
    private static array $checks = [];

    /**
     * Register a named check, usually in AppServiceProvider::boot().
     *
     * @param  Closure(User, mixed, Request): bool  $check  Return true to allow.
     */
    public static function define(string $name, Closure $check): void
    {
        self::$checks[$name] = $check;
    }

    public function handle(Request $request, Closure $next, string $check, ?string $param = null): Response
    {
        $user = $request->user();
        $resource = $param === null ? null : $request->route($param);

        if (! $user instanceof User) {
            $this->log('notice', 'Unauthenticated request to a protected resource', $request, null, $param, $resource);

            throw new AuthenticationException;
        }

        $allowed = isset(self::$checks[$check])
            ? (bool) (self::$checks[$check])($user, $resource, $request)
            : Gate::forUser($user)->allows($check, $resource);

        if (! $allowed) {
            $this->log('warning', 'Unauthorized access to a protected resource', $request, $user, $param, $resource);

            abort(403, 'You do not have access to this record.');
        }

        return $next($request);
    }

    private function log(string $level, string $message, Request $request, ?User $user, ?string $param, mixed $resource): void
    {
        Log::log($level, $message, [
            'user_id' => $user?->getAuthIdentifier(),
            'ip' => $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => $request->route()?->getName() ?? $request->route()?->uri(),
            'resource_param' => $param,
            'resource_id' => $resource instanceof Model ? $resource->getRouteKey() : (is_scalar($resource) ? $resource : null),
        ]);
    }
}

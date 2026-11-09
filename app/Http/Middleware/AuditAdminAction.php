<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Writes one admin.action audit line for every request an admin account makes
 * to an admin page. Alias: audit.admin (bootstrap/app.php). Put it after the
 * check that keeps other accounts out, so a refused request is logged as a
 * denial by that check and not here.
 *
 * A new admin route gets logged by being added to the admin group in
 * routes/web.php. Nobody has to remember a log call.
 *
 * The line has the method, path, route and the response status. It holds no
 * request body. The controllers add the details of what changed (for example
 * account.deleted names the target account).
 */
class AuditAdminAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isAdmin()) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            AuditLog::record('admin.action', ['status' => 500], AuditLog::FAILURE, $request);

            throw $e;
        }

        $status = $response->getStatusCode();
        AuditLog::record('admin.action', ['status' => $status], $status < 400 ? AuditLog::SUCCESS : AuditLog::FAILURE, $request);

        return $response;
    }
}

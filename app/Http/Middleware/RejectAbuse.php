<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AbuseGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects and logs two kinds of abuse before a controller runs:
 *
 * 1. A filled honeypot field (a POST only). The field is hidden from people,
 *    so only a bot fills it.
 * 2. A value that looks like an attack: script tags, SQL injection syntax or
 *    a string over AbuseGuard::MAX_LENGTH characters.
 *
 * The rejection is a validation error. A JSON route answers 400 (see
 * bootstrap/app.php) and a form page goes back with the error. Put this after
 * the throttle in a route's middleware list, so a visitor who keeps sending
 * attacks is blocked by the rate limit and the log does not fill up.
 * Password and token fields are never scanned or logged. They only get the
 * length check, and the log holds the length, not the value.
 */
class RejectAbuse
{
    public const MESSAGE = 'This input is not allowed.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {
            $trap = $request->input(AbuseGuard::HONEYPOT);

            if ($trap !== null && $trap !== '') {
                $this->log($request, 'honeypot', AbuseGuard::HONEYPOT, is_string($trap) ? $trap : null, null);

                throw ValidationException::withMessages(['form' => self::MESSAGE]);
            }
        }

        foreach ($this->fields($request->all()) as $field => $value) {
            $secret = in_array(explode('.', $field)[0], AbuseGuard::SECRET_FIELDS, true);

            if ($secret) {
                $hit = mb_strlen($value) > AbuseGuard::MAX_LENGTH ? 'too_long' : null;
            } else {
                $hit = AbuseGuard::check($value);
            }

            if ($hit !== null) {
                $this->log($request, $hit, $field, $secret ? null : $value, mb_strlen($value));

                throw ValidationException::withMessages([explode('.', $field)[0] => self::MESSAGE]);
            }
        }

        return $next($request);
    }

    /**
     * Every string in the input, with dotted names for nested values.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, string>
     */
    private function fields(array $input, string $prefix = ''): array
    {
        $found = [];

        foreach ($input as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_string($value)) {
                $found[$name] = $value;
            } elseif (is_array($value)) {
                $found += $this->fields($value, $name);
            }
        }

        return $found;
    }

    private function log(Request $request, string $reason, string $field, ?string $value, ?int $length): void
    {
        Log::warning('Rejected abusive input', [
            'reason' => $reason,
            'field' => $field,
            'length' => $length,
            'excerpt' => $value === null ? null : AbuseGuard::excerpt($value),
            'method' => $request->method(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);
    }
}

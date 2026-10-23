<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A public check that the app can reach its database and that the tables it
 * writes to exist. It returns only "ok" or "unavailable", never a table name
 * or an error message. The reason goes to the log.
 */
class HealthController extends Controller
{
    private const TABLES = ['users', 'override_logs', 'ai_feedback'];

    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $missing = array_values(array_filter(self::TABLES, fn (string $t) => ! Schema::hasTable($t)));
        } catch (\Throwable $e) {
            Log::error('health_failed', ['reason' => 'database', 'class' => $e::class]);

            return $this->reply('unavailable', 503);
        }

        if ($missing !== []) {
            Log::error('health_failed', ['reason' => 'missing_tables', 'tables' => $missing]);

            return $this->reply('unavailable', 503);
        }

        return $this->reply('ok', 200);
    }

    private function reply(string $status, int $code): JsonResponse
    {
        return response()->json(['status' => $status], $code, ['Cache-Control' => 'no-store']);
    }
}

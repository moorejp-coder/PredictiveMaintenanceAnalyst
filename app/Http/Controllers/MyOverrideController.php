<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\OverrideLog;
use App\Support\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A signed-in user's own override entries. Every route that names one entry
 * checks the owner on the server (OverrideLogPolicy) before it reads, changes
 * or deletes anything. A non-owner gets 403. The routes sit behind the auth
 * middleware, so a demo visitor and a guest get 401.
 */
class MyOverrideController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = OverrideLog::query()
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn (OverrideLog $log) => $this->present($log))->all()])
            ->header('Cache-Control', 'no-store');
    }

    public function show(OverrideLog $overrideLog): JsonResponse
    {
        Gate::authorize('view', $overrideLog);

        return response()->json(['data' => $this->present($overrideLog)])->header('Cache-Control', 'no-store');
    }

    /** Only the typed name and reason change, and the original is kept. The score and reading stay as PRISM showed them. */
    public function update(Request $request, OverrideLog $overrideLog): JsonResponse
    {
        Gate::authorize('update', $overrideLog);

        $request->merge([
            'operator_name' => PlainText::line($request->input('operator_name')),
            'reason' => PlainText::text($request->input('reason')),
        ]);

        $data = $request->validate([
            'operator_name' => ['required', 'string', 'min:2', 'max:100'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        // The first edit keeps what the operator first wrote, so the review
        // page still shows the original after a change.
        $overrideLog->update($data + [
            'edited_at' => now(),
            'original_operator_name' => $overrideLog->original_operator_name ?? $overrideLog->operator_name,
            'original_reason' => $overrideLog->original_reason ?? $overrideLog->reason,
        ]);

        return response()->json(['data' => $this->present($overrideLog)])->header('Cache-Control', 'no-store');
    }

    public function destroy(OverrideLog $overrideLog): JsonResponse
    {
        Gate::authorize('delete', $overrideLog);

        // A soft delete: the row stays for the review page, marked deleted.
        $overrideLog->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(OverrideLog $log): array
    {
        return [
            'id' => $log->uuid,
            'operator_name' => $log->operator_name,
            'reason' => $log->reason,
            'machine' => $log->machine,
            'scenario' => $log->scenario,
            'tick' => $log->tick,
            'risk_score' => $log->risk_score,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}

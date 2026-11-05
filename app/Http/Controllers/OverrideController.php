<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiFeedback;
use App\Models\OverrideLog;
use App\Models\User;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use App\Support\DemoMode;
use App\Support\DemoSamples;
use App\Support\PlainText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class OverrideController extends Controller
{
    public const MACHINE = 'Milling Machine M-01';

    /**
     * Record that an operator chose to keep running after a stop warning.
     *
     * The browser sends only the scenario and reading number. The server
     * rebuilds the reading and the score itself, so a forged request cannot
     * log a score PRISM never showed. Logging is allowed only for a reading
     * in the High band, the one that says to stop the machine.
     */
    public function store(Request $request): JsonResponse
    {
        // No typed name: the entry records the signed-in account instead
        // (data minimization). A name sent anyway is ignored.
        $request->merge(['reason' => PlainText::text($request->input('reason'))]);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'scenario' => ['required', Rule::in(array_keys(SensorSimulator::SCENARIOS))],
            'tick' => ['required', 'integer:strict', 'min:0', 'max:1000000'],
        ]);

        $reading = (new SensorSimulator)->reading($data['scenario'], (int) $data['tick']);
        $risk = (new RiskScorer('L'))->score($reading);

        if ($risk['band'] !== 'high') {
            return response()->json([
                'message' => 'An override is logged only when PRISM says to stop the machine.',
            ], 422);
        }

        $log = OverrideLog::create([
            // The signed-in account that sent the request is the "who" of the
            // entry. Null with the sign-in off.
            'user_id' => $request->user()?->getAuthIdentifier(),
            'reason' => $data['reason'],
            'machine' => self::MACHINE,
            'scenario' => $data['scenario'],
            'tick' => (int) $data['tick'],
            'risk_score' => $risk['score'],
            'action_shown' => $risk['action'],
            'reading' => $reading,
        ]);

        return response()->json([
            'id' => $log->uuid,
            'recorded_at' => $log->created_at?->toIso8601String(),
        ], 201);
    }

    /** The review page: newest 200 entries and the newest 50 AI ratings. */
    public function index(Request $request): View
    {
        // A read-only demo visitor sees fixed sample rows. This branch never
        // touches the database, so no real name or reason can reach the page.
        if (DemoMode::active($request)) {
            return $this->sample();
        }

        // If the ratings table cannot be read, say so on the page. A silent
        // zero would look the same as "nobody has rated an answer yet".
        $ratings = null;
        $helpful = 0;
        $wrong = 0;
        try {
            $ratings = AiFeedback::query()->latest('id')->limit(50)
                ->get(['uuid', 'created_at', 'step', 'rating', 'source', 'scenario', 'tick'])
                ->map(fn (AiFeedback $row): object => (object) [
                    'entry' => self::shortId($row->uuid),
                    'created_at' => $row->created_at,
                    'step' => $row->step,
                    'rating' => $row->rating,
                    'source' => $row->source,
                    'scenario' => $row->scenario,
                    'tick' => $row->tick,
                ]);
            $helpful = AiFeedback::query()->where('rating', 'helpful')->count();
            $wrong = AiFeedback::query()->where('rating', 'wrong')->count();
        } catch (\Throwable $e) {
            report($e);
        }

        $logs = OverrideLog::withTrashed()->latest('id')->limit(200)
            ->get(['uuid', 'user_id', 'created_at', 'reason', 'machine', 'risk_score', 'scenario', 'tick',
                'edited_at', 'original_reason', 'deleted_at']);
        // The account that wrote each entry, by email. The user id itself, the
        // stored sensor reading and the action text stay out of the page.
        $emails = User::query()->whereIn('id', $logs->pluck('user_id')->filter()->unique()->all())->pluck('email', 'id');

        return view('overrides', [
            'logs' => $logs
                ->map(fn (OverrideLog $log): object => (object) [
                    'uuid' => $log->uuid,
                    'entry' => self::shortId($log->uuid),
                    'created_at' => $log->created_at,
                    'account' => $log->user_id === null ? 'Not signed in' : ($emails[$log->user_id] ?? 'Deleted account'),
                    'reason' => $log->reason,
                    'machine' => $log->machine,
                    'risk_score' => $log->risk_score,
                    'scenario' => $log->scenario,
                    'tick' => $log->tick,
                    'edited_at' => $log->edited_at,
                    'original_reason' => $log->original_reason,
                    'deleted_at' => $log->deleted_at,
                ]),
            'cycle' => SensorSimulator::CYCLE,
            'ratings' => $ratings,
            'helpful' => $helpful,
            'wrong' => $wrong,
        ]);
    }

    /** The sample review page for the read-only Judge Demo. */
    private function sample(): View
    {
        $logs = collect(DemoSamples::overrides())->map(fn (array $row): object => (object) [
            'entry' => (string) $row['id'],
            'created_at' => Carbon::parse($row['at'], 'UTC'),
            'account' => $row['account'],
            'reason' => $row['reason'],
            'machine' => $row['machine'],
            'risk_score' => $row['risk_score'],
            'scenario' => $row['scenario'],
            'tick' => $row['tick'],
        ]);

        $ratings = collect(DemoSamples::ratings())->map(fn (array $row): object => (object) [
            'entry' => (string) $row['id'],
            'created_at' => Carbon::parse($row['at'], 'UTC'),
            'step' => $row['step'],
            'rating' => $row['rating'],
            'source' => $row['source'],
            'scenario' => $row['scenario'],
            'tick' => $row['tick'],
        ]);

        return view('overrides', [
            'sample' => true,
            'logs' => $logs,
            'cycle' => SensorSimulator::CYCLE,
            'ratings' => $ratings,
            'helpful' => $ratings->where('rating', 'helpful')->count(),
            'wrong' => $ratings->where('rating', 'wrong')->count(),
        ]);
    }

    /**
     * The last 8 characters of a public id. The review page shows this instead
     * of the database row number. The first characters of a UUIDv7 are the
     * time, so the end is the part that tells entries apart.
     */
    private static function shortId(?string $uuid): string
    {
        return $uuid === null ? '' : substr($uuid, -8);
    }
}

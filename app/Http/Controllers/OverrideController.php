<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiFeedback;
use App\Models\OverrideLog;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $data = $request->validate([
            'operator_name' => ['required', 'string', 'min:2', 'max:100'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'scenario' => ['required', Rule::in(array_keys(SensorSimulator::SCENARIOS))],
            'tick' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $reading = (new SensorSimulator)->reading($data['scenario'], (int) $data['tick']);
        $risk = (new RiskScorer('L'))->score($reading);

        if ($risk['band'] !== 'high') {
            return response()->json([
                'message' => 'An override is logged only when PRISM says to stop the machine.',
            ], 422);
        }

        $log = OverrideLog::create([
            'operator_name' => $data['operator_name'],
            'reason' => $data['reason'],
            'machine' => self::MACHINE,
            'scenario' => $data['scenario'],
            'tick' => (int) $data['tick'],
            'risk_score' => $risk['score'],
            'action_shown' => $risk['action'],
            'reading' => $reading,
        ]);

        return response()->json([
            'id' => $log->id,
            'recorded_at' => $log->created_at?->toIso8601String(),
        ], 201);
    }

    /** The review page: newest 200 entries and the newest 50 AI ratings. */
    public function index(): View
    {
        // If the ratings table cannot be read, say so on the page. A silent
        // zero would look the same as "nobody has rated an answer yet".
        $ratings = null;
        $helpful = 0;
        $wrong = 0;
        try {
            $ratings = AiFeedback::query()->latest('id')->limit(50)->get();
            $helpful = AiFeedback::query()->where('rating', 'helpful')->count();
            $wrong = AiFeedback::query()->where('rating', 'wrong')->count();
        } catch (\Throwable $e) {
            report($e);
        }

        return view('overrides', [
            'logs' => OverrideLog::query()->latest('id')->limit(200)->get(),
            'cycle' => SensorSimulator::CYCLE,
            'ratings' => $ratings,
            'helpful' => $helpful,
            'wrong' => $wrong,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard', [
            'scenarios' => SensorSimulator::SCENARIOS,
            'cycle' => SensorSimulator::CYCLE,
        ]);
    }

    public function readings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scenario' => ['required', Rule::in(array_keys(SensorSimulator::SCENARIOS))],
            'tick' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $reading = (new SensorSimulator)->reading($data['scenario'], (int) $data['tick']);

        return response()->json([
            'scenario' => $data['scenario'],
            'tick' => (int) $data['tick'],
            'reading' => $reading,
            'risk' => (new RiskScorer('L'))->score($reading),
        ])->header('X-Robots-Tag', 'noindex');
    }
}

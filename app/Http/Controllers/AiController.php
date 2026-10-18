<?php

namespace App\Http\Controllers;

use App\Services\Ai\Diagnoser;
use App\Services\Ai\WorkOrderStep;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Two small AI calls, one per route. The server rebuilds the reading from the
 * scenario and tick. It never trusts text from the browser, so a visitor
 * cannot put their own words in a prompt.
 */
class AiController extends Controller
{
    public function diagnosis(Request $request, Diagnoser $diagnoser): JsonResponse
    {
        $risk = $this->risk($request);

        return $this->respond($diagnoser->run(Diagnoser::input($risk)));
    }

    public function workOrder(Request $request, Diagnoser $diagnoser, WorkOrderStep $workOrder): JsonResponse
    {
        $risk = $this->risk($request);
        $diagnosis = $diagnoser->run(Diagnoser::input($risk));

        return $this->respond($workOrder->run(WorkOrderStep::input($risk, $diagnosis['data'])));
    }

    /** @return array<string, mixed> */
    private function risk(Request $request): array
    {
        $data = $request->validate([
            'scenario' => ['required', Rule::in(array_keys(SensorSimulator::SCENARIOS))],
            'tick' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $reading = (new SensorSimulator)->reading($data['scenario'], (int) $data['tick']);

        return (new RiskScorer('L'))->score($reading);
    }

    private function respond(array $result): JsonResponse
    {
        return response()->json($result)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'no-store');
    }
}

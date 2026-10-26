<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\Diagnoser;
use App\Services\Ai\Investigator;
use App\Services\Ai\WorkOrderStep;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Two AI steps, one per route. The server rebuilds the reading from the
 * scenario and tick. It never trusts text from the browser, so a visitor
 * cannot put their own words in a prompt.
 */
class AiController extends Controller
{
    public function diagnosis(Request $request, Diagnoser $diagnoser, Investigator $investigator): JsonResponse
    {
        return $this->respond($this->diagnose($request, $diagnoser, $investigator));
    }

    public function workOrder(Request $request, Diagnoser $diagnoser, Investigator $investigator, WorkOrderStep $workOrder): JsonResponse
    {
        $risk = $this->risk($request);
        $diagnosis = $this->diagnose($request, $diagnoser, $investigator);

        return $this->respond($workOrder->run(WorkOrderStep::input($risk, $diagnosis['data'])));
    }

    /**
     * The likely cause. The investigator goes first when it is on. If it fails,
     * the Diagnose call runs, and if that fails the built-in text shows. The
     * Diagnose call runs again only when the model answered but the answer
     * failed a check. After a timeout or an API error, a second call would only
     * make the visitor wait longer.
     *
     * @return array<string, mixed>
     */
    private function diagnose(Request $request, Diagnoser $diagnoser, Investigator $investigator): array
    {
        $risk = $this->risk($request);
        $input = Diagnoser::input($risk);

        if (! config('prism.investigator')) {
            return $diagnoser->run($input) + ['trace' => []];
        }

        $data = $request->validate([
            'scenario' => ['required', Rule::in(array_keys(SensorSimulator::SCENARIOS))],
            'tick' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);
        $result = $investigator->run($data['scenario'], (int) $data['tick'], $risk);
        if ($result['source'] !== 'fallback') {
            return $result;
        }

        return in_array($result['reason'], ['bad_reply', 'ungrounded'], true)
            ? $diagnoser->run($input) + ['trace' => []]
            : $diagnoser->offline($input, (string) $result['reason']) + ['trace' => []];
    }

    /** @return array<string, mixed> */
    /** @return array{score: int, band: string, band_label: string, action: string, explanation: string, drivers: list<array{key: string, label: string, stress: int, scored: bool}>, metrics: array<string, float>} */
    private function risk(Request $request): array
    {
        $data = $request->validate([
            'scenario' => ['required', Rule::in(array_keys(SensorSimulator::SCENARIOS))],
            'tick' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $reading = (new SensorSimulator)->reading($data['scenario'], (int) $data['tick']);

        return (new RiskScorer('L'))->score($reading);
    }

    /** @param  array<string, mixed>  $result */
    private function respond(array $result): JsonResponse
    {
        return response()->json($result)
            ->header('X-Robots-Tag', 'noindex')
            ->header('Cache-Control', 'no-store');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiFeedback;
use App\Services\SensorSimulator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AiFeedbackController extends Controller
{
    /**
     * Record a "helpful" or "wrong" rating for one AI answer.
     * Every field comes from a fixed list or a number, so no visitor text is stored.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'step' => ['required', Rule::in(['diagnosis', 'work_order'])],
            'rating' => ['required', Rule::in(['helpful', 'wrong'])],
            'source' => ['required', Rule::in(['ai', 'cache', 'rules'])],
            'scenario' => ['required', Rule::in(array_keys(SensorSimulator::SCENARIOS))],
            'tick' => ['required', 'integer:strict', 'min:0', 'max:1000000'],
        ]);

        $row = AiFeedback::create($data);

        return response()->json(['id' => $row->id], 201);
    }
}

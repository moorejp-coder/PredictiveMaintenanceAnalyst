<?php

namespace App\Services\Ai;

/**
 * Call 2. Drafts a short work order from the diagnosis.
 *
 * Code sets the priority from the band. The model writes only the title and
 * the steps, and sees only the diagnosis from call 1.
 */
final class WorkOrderStep extends AiStep
{
    /** The one simulated machine. The server never takes a machine name from the browser. */
    private const MACHINE = 'M-01';

    private const PRIORITY = [
        'high' => 'Urgent: stop the machine and inspect now',
        'elevated' => 'Schedule at the next stop',
        'low' => 'None',
    ];

    /**
     * @param  array{score: int, band: string, action: string, drivers: list<array{key: string, label: string, stress: int}>}  $risk
     * @param  array{cause: string, checks: list<string>}  $diagnosis
     */
    public static function input(array $risk, array $diagnosis): array
    {
        $drivers = [];
        foreach ($risk['drivers'] as $driver) {
            if ($driver['stress'] >= 40) {
                $drivers[] = $driver['label'];
            }
        }

        return [
            'machine' => self::MACHINE,
            'band' => $risk['band'],
            'priority' => self::PRIORITY[$risk['band']],
            'action' => $risk['action'],
            'drivers' => $drivers,
            'cause' => $diagnosis['cause'],
            'checks' => $diagnosis['checks'],
        ];
    }

    protected function name(): string
    {
        return 'work_order';
    }

    protected function maxTokens(): int
    {
        return 260;
    }

    protected function system(): string
    {
        return 'You write a short maintenance work order for a CNC milling machine from a JSON diagnosis. '
            .'Use only facts in the JSON. Do not invent parts, part numbers, times or costs. '
            .'Do not mention the priority, it is set elsewhere. '
            .'Reply with JSON only, no other text: '
            .'{"title": "under 10 words", "steps": ["3 or 4 imperative steps, each under 15 words"]}';
    }

    protected function shouldCall(array $input): bool
    {
        return $input['band'] !== 'low';
    }

    protected function parse(string $text, array $input): ?array
    {
        $json = self::decodeJson($text);
        if ($json === null) {
            return null;
        }

        $title = self::cleanString($json['title'] ?? null, 100);
        $steps = self::cleanList($json['steps'] ?? null, 2, 5, 160);

        return $title === null || $steps === null
            ? null
            : ['title' => $title, 'priority' => $input['priority'], 'steps' => $steps];
    }

    protected function fallback(array $input): array
    {
        if ($input['band'] === 'low') {
            return ['title' => 'No work order needed', 'priority' => $input['priority'], 'steps' => []];
        }

        return [
            'title' => 'Inspect '.$input['machine'].': '.($input['drivers'][0] ?? 'sensor readings'),
            'priority' => $input['priority'],
            'steps' => array_values(array_unique(array_merge($input['checks'], [$input['action']]))),
        ];
    }
}

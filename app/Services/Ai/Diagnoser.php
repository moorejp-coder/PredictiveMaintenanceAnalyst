<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Call 1. Names the likely cause and two physical checks.
 *
 * Input is the score result cut down to about 150 tokens: the score, the band,
 * the drivers at 40 or above, the fact sentences already built by the checks,
 * and the derived metrics. The model never sees the rules or the raw history.
 */
final class Diagnoser extends AiStep
{
    /**
     * Built-in checks for the fallback, keyed by the driver label from each Check.
     * General shop practice. They hold no numbers and no part names.
     */
    private const CHECKS = [
        'Tool wear' => ['Pull the tool and look at the cutting edge for wear.', 'Change the tool if the edge is worn or chipped.'],
        'Heat buildup' => ['Check the coolant level and flow.', 'Clear chips and dust from the machine vents.'],
        'Power draw' => ['Turn the spindle by hand and feel for drag.', 'Compare the cut load with the tool and feed settings.'],
        'Overstrain' => ['Lower the feed rate or the depth of cut.', 'Check that the tool and the workpiece are held firmly.'],
        'Vibration' => ['Check the tool holder and the workpiece clamping.', 'Listen for rough spindle bearings.'],
        'Machine hours' => ['Check the maintenance log for the last service.', 'Book a routine service at the next stop.'],
    ];

    /**
     * @param  array{score: int, band: string, action: string, explanation: string, drivers: list<array{key: string, label: string, stress: int, scored?: bool}>, metrics: array<string, float>}  $risk
     * @return array{score: int, band: string, action: string, facts: string, drivers: list<array{driver: string, stress: int}>, metrics: array<string, float>}
     */
    public static function input(array $risk): array
    {
        $drivers = [];
        foreach ($risk['drivers'] as $driver) {
            // Context-only drivers (rotational speed) are not failure modes.
            if (($driver['scored'] ?? true) && $driver['stress'] >= 40) {
                $drivers[] = ['driver' => $driver['label'], 'stress' => $driver['stress']];
            }
        }

        return [
            'score' => $risk['score'],
            'band' => $risk['band'],
            'action' => $risk['action'],
            'facts' => $risk['explanation'],
            'drivers' => $drivers,
            'metrics' => $risk['metrics'],
        ];
    }

    protected function name(): string
    {
        return 'diagnose';
    }

    protected function maxTokens(): int
    {
        return 220;
    }

    protected function system(): string
    {
        return 'You help a technician at a small machine shop. You get a JSON risk result for one CNC milling machine. '
            .'Use only facts in the JSON. Do not invent numbers, parts or causes. '
            .'Reply with JSON only, no other text: '
            .'{"cause": "one or two plain sentences, under 40 words", '
            .'"checks": ["first physical check, under 15 words", "second physical check, under 15 words"]}';
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

        $cause = self::cleanString($json['cause'] ?? null, 400);
        $checks = self::cleanList($json['checks'] ?? null, 1, 2, 160);

        return $cause === null || $checks === null ? null : ['cause' => $cause, 'checks' => $checks];
    }

    protected function fallback(array $input): array
    {
        $top = null;
        foreach ($input['drivers'] as $driver) {
            if ($top === null || $driver['stress'] > $top['stress']) {
                $top = $driver;
            }
        }

        return [
            'cause' => $input['facts'],
            'checks' => self::CHECKS[$top['driver'] ?? ''] ?? [$input['action']],
        ];
    }
}

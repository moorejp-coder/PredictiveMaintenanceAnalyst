<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\RiskScorer;
use App\Services\SensorSimulator;

/**
 * Fixed, made-up rows for the sample override log shown to the read-only Judge
 * Demo. Nothing here is read from the database, so a demo visitor never sees
 * a real operator's name or reason. The names and reasons are invented. The
 * score and the reading are rebuilt from the scenario and reading number by
 * the same code that scores the live dashboard, as the real log does.
 */
final class DemoSamples
{
    /** @var list<array{id: int, at: string, operator: string, reason: string, scenario: string, tick: int}> */
    private const OVERRIDES = [
        ['id' => 3, 'at' => '2026-01-14 15:42:10', 'operator' => 'Sample Operator C', 'reason' => 'Last part of the shift. A supervisor is watching the spindle and will stop it if the sound changes.', 'scenario' => 'overload', 'tick' => 59],
        ['id' => 2, 'at' => '2026-01-14 09:05:31', 'operator' => 'Sample Operator B', 'reason' => 'Coolant line was just refilled. Running one more pass before the scheduled tool change.', 'scenario' => 'overheating', 'tick' => 59],
        ['id' => 1, 'at' => '2026-01-13 21:18:47', 'operator' => 'Sample Operator A', 'reason' => 'Customer order due at 06:00. Tool change is booked for the next shift.', 'scenario' => 'worn_tool', 'tick' => 59],
    ];

    /** @var list<array{id: int, at: string, step: string, rating: string, source: string, scenario: string, tick: int}> */
    private const RATINGS = [
        ['id' => 3, 'at' => '2026-01-14 15:43:02', 'step' => 'work_order', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 59],
        ['id' => 2, 'at' => '2026-01-14 15:43:02', 'step' => 'diagnosis', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 59],
        ['id' => 1, 'at' => '2026-01-13 21:19:20', 'step' => 'diagnosis', 'rating' => 'wrong', 'source' => 'rules', 'scenario' => 'worn_tool', 'tick' => 59],
    ];

    /**
     * Sample override rows, newest first, shaped like the rows on the real page.
     *
     * @return list<array{id: int, at: string, operator: string, reason: string, machine: string, risk_score: int, scenario: string, tick: int}>
     */
    public static function overrides(): array
    {
        $simulator = new SensorSimulator;
        $scorer = new RiskScorer('L');

        return array_map(function (array $row) use ($simulator, $scorer): array {
            $risk = $scorer->score($simulator->reading($row['scenario'], $row['tick']));

            return [
                'id' => $row['id'],
                'at' => $row['at'],
                'operator' => $row['operator'],
                'reason' => $row['reason'],
                'machine' => 'Milling Machine M-01',
                'risk_score' => $risk['score'],
                'scenario' => $row['scenario'],
                'tick' => $row['tick'],
            ];
        }, self::OVERRIDES);
    }

    /**
     * Sample AI rating rows, newest first.
     *
     * @return list<array{id: int, at: string, step: string, rating: string, source: string, scenario: string, tick: int}>
     */
    public static function ratings(): array
    {
        return self::RATINGS;
    }
}

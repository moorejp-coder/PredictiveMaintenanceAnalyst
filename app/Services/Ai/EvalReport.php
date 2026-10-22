<?php

namespace App\Services\Ai;

/**
 * Turns the rows of an investigator evaluation run into counts and a Markdown
 * table. It holds no model calls. Every figure is a count or an average of the
 * rows it is given, so a report never states more than the run measured.
 *
 * @phpstan-type EvalRow array{scenario: string, tick: int, band: string, source: string, reason: string|null, tools: list<string>, tested_fix: bool, tokens_in: int|null, tokens_out: int|null, seconds: float}
 * @phpstan-type EvalSummary array{runs: int, accepted: int, fallback: int, reasons: array<string, int>, tested_fix: int, used_learned: int, used_what_if: int, avg_tools: float, avg_tokens_in: float|null, avg_tokens_out: float|null, median_seconds: float, p95_seconds: float}
 */
final class EvalReport
{
    /**
     * @param  list<EvalRow>  $rows
     * @return EvalSummary
     */
    public static function summarize(array $rows): array
    {
        $runs = count($rows);
        $accepted = 0;
        $reasons = [];
        $testedFix = 0;
        $learned = 0;
        $whatIf = 0;
        $toolCalls = 0;
        $tokensIn = [];
        $tokensOut = [];
        $seconds = [];

        foreach ($rows as $row) {
            $seconds[] = $row['seconds'];
            $toolCalls += count($row['tools']);
            $learned += in_array('get_learned_estimate', $row['tools'], true) ? 1 : 0;
            $whatIf += in_array('what_if', $row['tools'], true) ? 1 : 0;
            if ($row['source'] === 'ai') {
                $accepted++;
                $testedFix += $row['tested_fix'] ? 1 : 0;
                if ($row['tokens_in'] !== null && $row['tokens_out'] !== null) {
                    $tokensIn[] = $row['tokens_in'];
                    $tokensOut[] = $row['tokens_out'];
                }
            } else {
                $reason = $row['reason'] ?? 'unknown';
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }
        ksort($reasons);

        return [
            'runs' => $runs,
            'accepted' => $accepted,
            'fallback' => $runs - $accepted,
            'reasons' => $reasons,
            'tested_fix' => $testedFix,
            'used_learned' => $learned,
            'used_what_if' => $whatIf,
            'avg_tools' => $runs === 0 ? 0.0 : round($toolCalls / $runs, 1),
            'avg_tokens_in' => $tokensIn === [] ? null : round(array_sum($tokensIn) / count($tokensIn), 0),
            'avg_tokens_out' => $tokensOut === [] ? null : round(array_sum($tokensOut) / count($tokensOut), 0),
            'median_seconds' => self::percentile($seconds, 50),
            'p95_seconds' => self::percentile($seconds, 95),
        ];
    }

    /**
     * Nearest-rank percentile.
     *
     * @param  list<float>  $values
     */
    public static function percentile(array $values, int $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $rank = (int) ceil($p / 100 * count($values));

        return round($values[max(1, $rank) - 1], 1);
    }

    public static function percent(int $part, int $whole): string
    {
        return $whole === 0 ? 'n/a' : number_format(100 * $part / $whole, 1).'%';
    }

    /**
     * @param  EvalSummary  $s
     * @param  array{date: string, model: string, every: int, scenarios: list<string>}  $meta
     */
    public static function markdown(array $s, array $meta): string
    {
        $reasons = $s['reasons'] === []
            ? 'none'
            : implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($s['reasons']), $s['reasons']));

        $lines = [
            '# Investigator evaluation',
            '',
            "Run on {$meta['date']} with model `{$meta['model']}`. Scenarios: ".implode(', ', $meta['scenarios']).". One reading every {$meta['every']} ticks. Low-band readings are skipped, because the AI does not run on them.",
            '',
            'Reproduce with `php artisan prism:eval-investigator`. Results vary from run to run.',
            '',
            '| Measure | Result |',
            '|---|---|',
            "| Readings investigated | {$s['runs']} |",
            "| Answers accepted by the code checks | {$s['accepted']} of {$s['runs']} (".self::percent($s['accepted'], $s['runs']).') |',
            "| Fell back to the built-in text | {$s['fallback']} of {$s['runs']} ({$reasons}) |",
            "| Runs that called what_if | {$s['used_what_if']} of {$s['runs']} (".self::percent($s['used_what_if'], $s['runs']).') |',
            "| Runs that called get_learned_estimate | {$s['used_learned']} of {$s['runs']} (".self::percent($s['used_learned'], $s['runs']).') |',
            "| Accepted answers with a tested fix | {$s['tested_fix']} of {$s['accepted']} (".self::percent($s['tested_fix'], $s['accepted']).') |',
            "| Tool calls per run | {$s['avg_tools']} |",
            '| Tokens per accepted answer (input, output) | '.($s['avg_tokens_in'] === null ? 'n/a' : "{$s['avg_tokens_in']}, {$s['avg_tokens_out']}").' |',
            "| Time per run, median and 95th percentile | {$s['median_seconds']} s, {$s['p95_seconds']} s |",
            '',
            'Limits:',
            '- The readings are simulated. The run shows how the investigator behaves, not how it performs on a real machine.',
            '- "Accepted" means the answer passed the code checks: valid JSON, only tools it called, and only numbers it was given. A passed check does not prove the advice is correct. No technician graded the answers.',
            '- Each reading ran once. Nearby ticks are not independent readings of different machines.',
            '',
        ];

        return implode("\n", $lines);
    }
}

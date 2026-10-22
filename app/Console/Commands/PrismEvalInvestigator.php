<?php

namespace App\Console\Commands;

use App\Services\Ai\AiClient;
use App\Services\Ai\EvalReport;
use App\Services\Ai\Investigator;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs the investigator on a fixed grid of simulated readings and reports how
 * often the answer passed the code checks, which tools it called and what it
 * cost in tokens and time. It calls the live Anthropic API, so it needs
 * ANTHROPIC_API_KEY and uses tokens. Use --dry-run to see the plan first.
 *
 * The report counts what happened. It does not grade the advice.
 */
final class PrismEvalInvestigator extends Command
{
    protected $signature = 'prism:eval-investigator
        {--scenario=* : Scenario to run (worn_tool, overheating, overload, healthy). Repeat for several. Default: all.}
        {--every=3 : Run one reading every N ticks of the 60 tick cycle.}
        {--limit=60 : Stop after this many investigations, to cap token use.}
        {--pause=0 : Seconds to wait between calls.}
        {--dry-run : List the readings that would run. Makes no API call.}
        {--write= : Write the Markdown report to this path, for example docs/EVAL.md.}';

    protected $description = 'Run the AI investigator on simulated readings and report how often its answers pass the code checks';

    public function handle(): int
    {
        $scenarios = array_values(array_filter((array) $this->option('scenario'), 'is_string'));
        $scenarios = $scenarios === [] ? array_keys(SensorSimulator::SCENARIOS) : $scenarios;
        foreach ($scenarios as $name) {
            if (! isset(SensorSimulator::SCENARIOS[$name])) {
                $this->error("Unknown scenario: {$name}");

                return self::FAILURE;
            }
        }
        $every = (int) $this->option('every');
        $limit = (int) $this->option('limit');
        $pause = max(0, (int) $this->option('pause'));
        if ($every < 1 || $every > SensorSimulator::CYCLE || $limit < 1) {
            $this->error('--every must be from 1 to '.SensorSimulator::CYCLE.' and --limit must be at least 1.');

            return self::FAILURE;
        }

        $plan = $this->plan($scenarios, $every, $limit);
        $this->line(sprintf('Readings in the Elevated or High band: %d (run limit %d).', count($plan['readings']), $limit));
        $this->line(sprintf('Skipped as Low: %d.', $plan['skipped']));

        if ($this->option('dry-run')) {
            foreach ($plan['readings'] as $r) {
                $this->line(sprintf('  %-12s tick %-3d score %-3d %s', $r['scenario'], $r['tick'], $r['risk']['score'], $r['risk']['band']));
            }

            return self::SUCCESS;
        }

        $client = AiClient::fromConfig();
        if (! $client->enabled()) {
            $this->error('ANTHROPIC_API_KEY is not set, so there is nothing to evaluate. Use --dry-run to see the plan.');

            return self::FAILURE;
        }

        // A fresh cache, so no earlier answer is served in place of a new one.
        Cache::setDefaultDriver('array');
        $investigator = new Investigator($client);
        $rows = [];
        $failStreak = 0;
        foreach ($plan['readings'] as $i => $r) {
            if ($i > 0 && $pause > 0) {
                sleep($pause);
            }
            $start = microtime(true);
            $result = $investigator->run($r['scenario'], $r['tick'], $r['risk']);
            $seconds = microtime(true) - $start;

            $rows[] = [
                'scenario' => $r['scenario'],
                'tick' => $r['tick'],
                'band' => $r['risk']['band'],
                'source' => $result['source'],
                'reason' => $result['reason'],
                'tools' => array_values(array_map(fn ($t) => $t['tool'], array_filter($result['trace'], fn ($t) => $t['ok']))),
                'tested_fix' => is_array($result['data']) && isset($result['data']['tested_fix']),
                'tokens_in' => $result['tokens']['input'] ?? null,
                'tokens_out' => $result['tokens']['output'] ?? null,
                'seconds' => $seconds,
            ];
            $this->line(sprintf('  %-12s tick %-3d %s%s', $r['scenario'], $r['tick'], $result['source'], $result['reason'] === null ? '' : " ({$result['reason']})"));

            // Stop early when the API keeps failing. Every further call would fail the same way.
            $failStreak = in_array($result['reason'], ['api_error', 'timeout'], true) ? $failStreak + 1 : 0;
            if ($failStreak >= 3) {
                $this->error('Three calls in a row failed, so the run stopped. The reason is in storage/logs/laravel.log: look for the last "ai_call_failed" line and its error_message.');

                return self::FAILURE;
            }
        }

        $summary = EvalReport::summarize($rows);
        $meta = [
            'date' => now()->format('F j, Y'),
            'model' => (string) config('services.anthropic.model'),
            'every' => $every,
            'scenarios' => $scenarios,
        ];
        $report = EvalReport::markdown($summary, $meta);
        $this->newLine();
        $this->line($report);

        $path = $this->option('write');
        if (is_string($path) && $path !== '') {
            $target = str_starts_with($path, '/') ? $path : base_path($path);
            file_put_contents($target, $report);
            $this->info("Report written to {$target}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $scenarios
     * @return array{readings: list<array{scenario: string, tick: int, risk: array{score: int, band: string, action: string, explanation: string, drivers: list<array{key: string, label: string, stress: int, scored?: bool}>, metrics: array<string, float>}}>, skipped: int}
     */
    private function plan(array $scenarios, int $every, int $limit): array
    {
        $sim = new SensorSimulator;
        $scorer = new RiskScorer('L');
        $readings = [];
        $skipped = 0;
        foreach ($scenarios as $scenario) {
            for ($tick = 0; $tick < SensorSimulator::CYCLE; $tick += $every) {
                $risk = $scorer->score($sim->reading($scenario, $tick));
                if ($risk['band'] === 'low') {
                    $skipped++;

                    continue;
                }
                if (count($readings) < $limit) {
                    $readings[] = ['scenario' => $scenario, 'tick' => $tick, 'risk' => $risk];
                }
            }
        }

        return ['readings' => $readings, 'skipped' => $skipped];
    }
}

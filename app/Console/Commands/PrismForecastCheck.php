<?php

namespace App\Console\Commands;

use App\Services\Ml\ForecastCheck;
use App\Services\SensorSimulator;
use Illuminate\Console\Command;

/**
 * Checks the straight-line projection behind the learned estimate against the
 * simulator. It needs no API key and makes no API call.
 */
final class PrismForecastCheck extends Command
{
    protected $signature = 'prism:forecast-check
        {--scenario=* : Scenario to check. Repeat for several. Default: all.}
        {--every=3 : One start reading every N ticks.}
        {--write= : Write the Markdown report to this path, for example docs/FORECAST.md.}';

    protected $description = 'Check the projected chance of failure against the simulator';

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
        if ($every < 1 || $every > 30) {
            $this->error('--every must be from 1 to 30.');

            return self::FAILURE;
        }

        $report = ForecastCheck::markdown(ForecastCheck::run($scenarios, $every), $scenarios, $every, now()->format('F j, Y'));
        $this->line($report);

        $path = $this->option('write');
        if (is_string($path) && $path !== '') {
            $target = str_starts_with($path, '/') ? $path : base_path($path);
            file_put_contents($target, $report);
            $this->info("Report written to {$target}");
        }

        return self::SUCCESS;
    }
}

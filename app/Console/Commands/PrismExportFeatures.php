<?php

namespace App\Console\Commands;

use App\Services\RiskScorer;
use Illuminate\Console\Command;

/**
 * Writes one CSV row per AI4I 2020 record with the raw readings, the failure
 * labels, PRISM's own rule score and the stress of each AI4I check. The
 * training script in ml/ reads this file, so the model sees exactly the
 * numbers the dashboard computes.
 */
final class PrismExportFeatures extends Command
{
    protected $signature = 'prism:export-features
        {file=data/ai4i2020.csv : Path to the AI4I 2020 CSV. A relative path is tried from the project folder.}
        {--out= : Write the CSV to this file. Without it the CSV goes to the screen.}';

    protected $description = 'Export AI4I records with PRISM rule scores and check stresses for model training';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read the file: {$path}");

            return self::FAILURE;
        }

        $in = fopen($path, 'r');
        $header = fgetcsv($in, null, ',', '"', '\\');
        $col = [];
        foreach ((is_array($header) ? $header : []) as $i => $name) {
            $name = strtolower(trim(ltrim((string) $name, "\xEF\xBB\xBF")));
            $col[trim((string) preg_replace('/\s*\[.*\]\s*/', '', $name))] = $i;
        }
        $needed = ['udi', 'type', 'air temperature', 'process temperature', 'rotational speed', 'torque', 'tool wear',
            'machine failure', 'twf', 'hdf', 'pwf', 'osf', 'rnf'];
        $missing = array_values(array_diff($needed, array_keys($col)));
        if ($missing !== []) {
            fclose($in);
            $this->error('Missing columns: '.implode(', ', $missing));

            return self::FAILURE;
        }

        $target = (string) $this->option('out');
        $out = $target !== '' ? fopen($target, 'w') : fopen('php://output', 'w');
        if ($out === false) {
            fclose($in);
            $this->error("Cannot write to: {$target}");

            return self::FAILURE;
        }
        fputcsv($out, ['UDI', 'Type', 'air', 'proc', 'rpm', 'torque', 'wear', 'fail', 'TWF', 'HDF', 'PWF', 'OSF', 'RNF',
            'rule_score', 's_tool_wear', 's_heat', 's_power', 's_overstrain'], ',', '"', '\\');

        $scorers = [];
        $rows = 0;
        while (($r = fgetcsv($in, null, ',', '"', '\\')) !== false) {
            if ($r === [null] || count($r) < count($header)) {
                continue;
            }
            $type = strtoupper(trim((string) $r[$col['type']]));
            if (! in_array($type, ['L', 'M', 'H'], true)) {
                continue;
            }
            $scorers[$type] ??= new RiskScorer($type);
            $reading = [
                'air_temp_k' => (float) $r[$col['air temperature']],
                'process_temp_k' => (float) $r[$col['process temperature']],
                'rotational_speed_rpm' => (float) $r[$col['rotational speed']],
                'torque_nm' => (float) $r[$col['torque']],
                'tool_wear_min' => (float) $r[$col['tool wear']],
            ];
            $result = $scorers[$type]->score($reading);
            $stress = [];
            foreach ($result['drivers'] as $d) {
                $stress[$d['key']] = $d['stress'];
            }
            fputcsv($out, [
                $r[$col['udi']], $type, $reading['air_temp_k'], $reading['process_temp_k'], $reading['rotational_speed_rpm'],
                $reading['torque_nm'], $reading['tool_wear_min'], $r[$col['machine failure']],
                $r[$col['twf']], $r[$col['hdf']], $r[$col['pwf']], $r[$col['osf']], $r[$col['rnf']],
                $result['score'], $stress['tool_wear'] ?? 0, $stress['heat'] ?? 0, $stress['power'] ?? 0, $stress['overstrain'] ?? 0,
            ], ',', '"', '\\');
            $rows++;
        }
        fclose($in);
        fclose($out);
        if ($target !== '') {
            $this->info("Wrote {$rows} rows to {$target}");
        }

        return self::SUCCESS;
    }
}

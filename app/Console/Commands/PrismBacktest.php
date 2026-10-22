<?php

namespace App\Console\Commands;

use App\Services\RiskScorer;
use Illuminate\Console\Command;

/**
 * Scores every record in the AI4I 2020 CSV with the same RiskScorer the
 * dashboard uses and counts how many records in each band had a machine
 * failure. Reproduces the backtest figures in the README.
 *
 * AI4I has no vibration or run hours column, so only the four AI4I checks
 * (tool wear, heat, power, overstrain) move these scores. The rules use limits
 * taken from AI4I and the test runs on AI4I, so the result checks that the
 * rules agree with the dataset labels. It is not validation on a real machine.
 */
final class PrismBacktest extends Command
{
    protected $signature = 'prism:backtest
        {file=data/ai4i2020.csv : Path to the AI4I 2020 CSV. A relative path is tried from the project folder.}
        {--quality=record : "record" scores each row with its own product quality variant (L, M or H). Pass L, M or H to force one variant for every row.}
        {--keep-random : Keep rows whose only failure mode is random failure (RNF). They are left out by default.}';

    protected $description = 'Backtest the PRISM risk bands against the AI4I 2020 dataset';

    private const MODES = ['TWF', 'HDF', 'PWF', 'OSF'];

    private const BANDS = ['low' => 'Low', 'elevated' => 'Elevated', 'high' => 'High'];

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) && is_file(base_path($path))) {
            $path = base_path($path);
        }
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read the file: {$path}");
            $this->line('Download ai4i2020.csv from https://archive.ics.uci.edu/dataset/601/ai4i+2020+predictive+maintenance+dataset and pass its path.');

            return self::FAILURE;
        }

        $quality = strtoupper((string) $this->option('quality'));
        if (! in_array($quality, ['RECORD', 'L', 'M', 'H'], true)) {
            $this->error('--quality must be record, L, M or H.');

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = $handle === false ? false : fgetcsv($handle, null, ',', '"', '\\');
        if ($handle === false || ! is_array($header)) {
            if ($handle !== false) {
                fclose($handle);
            }
            $this->error("Cannot read a header row from: {$path}");

            return self::FAILURE;
        }
        $col = $this->columns($header);
        $needed = ['type', 'air temperature', 'process temperature', 'rotational speed', 'torque', 'tool wear',
            'machine failure', 'twf', 'hdf', 'pwf', 'osf', 'rnf'];
        $missing = array_values(array_diff($needed, array_keys($col)));
        if ($missing !== []) {
            fclose($handle);
            $this->error('Missing columns: '.implode(', ', $missing));
            $this->line('Columns found: '.implode(', ', array_keys($col)));

            return self::FAILURE;
        }

        $scorers = [];
        $total = 0;
        $randomAny = 0;
        $randomOnly = 0;
        $skippedType = 0;
        $keepRandom = (bool) $this->option('keep-random');
        $bands = [];
        foreach (array_keys(self::BANDS) as $bandKey) {
            $bands[$bandKey] = ['records' => 0, 'failed' => 0];
        }
        $unflagged = array_fill_keys(array_keys(self::BANDS), 0);
        $modeBands = [];
        foreach (self::MODES as $mode) {
            $modeBands[$mode] = array_fill_keys(array_keys(self::BANDS), 0);
        }

        while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            if ($row === [null] || count($row) < count($header)) {
                continue;
            }
            $total++;

            $modes = [];
            foreach (self::MODES as $mode) {
                $modes[$mode] = (int) $row[$col[strtolower($mode)]] === 1;
            }
            $rnf = (int) $row[$col['rnf']] === 1;
            $only = $rnf && ! in_array(true, $modes, true);
            $randomAny += $rnf ? 1 : 0;
            $randomOnly += $only ? 1 : 0;
            if ($only && ! $keepRandom) {
                continue;
            }

            $q = $quality === 'RECORD' ? strtoupper(trim((string) $row[$col['type']])) : $quality;
            if (! in_array($q, ['L', 'M', 'H'], true)) {
                $skippedType++;

                continue;
            }
            $scorers[$q] ??= new RiskScorer($q);

            $result = $scorers[$q]->score([
                'air_temp_k' => (float) $row[$col['air temperature']],
                'process_temp_k' => (float) $row[$col['process temperature']],
                'rotational_speed_rpm' => (float) $row[$col['rotational speed']],
                'torque_nm' => (float) $row[$col['torque']],
                'tool_wear_min' => (float) $row[$col['tool wear']],
            ]);

            $band = $result['band'];
            $failed = (int) $row[$col['machine failure']] === 1;
            $entry = $bands[$band] ?? ['records' => 0, 'failed' => 0];
            $entry['records']++;
            $entry['failed'] += $failed ? 1 : 0;
            $bands[$band] = $entry;
            // Labeled as a machine failure, but no failure mode column is set.
            if ($failed && ! $rnf && ! in_array(true, $modes, true)) {
                $unflagged[$band]++;
            }
            foreach ($modes as $mode => $on) {
                if ($on) {
                    $modeBands[$mode][$band]++;
                }
            }
        }
        fclose($handle);

        $scored = array_sum(array_column($bands, 'records'));
        $failures = array_sum(array_column($bands, 'failed'));

        $this->line('PRISM backtest on '.basename($path));
        $this->line('Product quality variant: '.($quality === 'RECORD' ? "each record's own" : $quality));
        $this->line("Records in file: {$total}");
        $this->line("Random failure records (RNF = 1): {$randomAny}");
        $this->line("Random failure is the only failure mode: {$randomOnly}");
        $this->line($keepRandom
            ? 'Left out: 0 (--keep-random)'
            : "Left out (random failure only): {$randomOnly}");
        if ($skippedType > 0) {
            $this->line("Skipped for an unknown product type: {$skippedType}");
        }
        $this->line("Records scored: {$scored}");
        $this->newLine();

        $rows = [];
        foreach (self::BANDS as $key => $label) {
            $rows[] = [$label, $bands[$key]['records'], $bands[$key]['failed'], $this->percent($bands[$key]['failed'], $bands[$key]['records'])];
        }
        $this->table(['Band', 'Records', 'Failed', 'Failure rate'], $rows);

        $this->line("Failures among scored records: {$failures}");
        foreach (self::BANDS as $key => $label) {
            $this->line(sprintf('  In %s: %d (%s)', $label, $bands[$key]['failed'], $this->percent($bands[$key]['failed'], $failures)));
        }
        $this->line(sprintf(
            'Failures with no failure mode flagged (label only): %d (Low %d, Elevated %d, High %d)',
            array_sum($unflagged),
            $unflagged['low'],
            $unflagged['elevated'],
            $unflagged['high'],
        ));
        $this->newLine();

        $this->line('Records with each AI4I failure mode, by band (a record can have more than one mode):');
        foreach (self::MODES as $mode) {
            $this->line(sprintf(
                '  %s: low %d, elevated %d, high %d',
                $mode,
                $modeBands[$mode]['low'],
                $modeBands[$mode]['elevated'],
                $modeBands[$mode]['high'],
            ));
        }
        $this->newLine();
        $this->line('Read with care: the rules use limits taken from AI4I and this test runs on AI4I, so it checks agreement with the dataset labels, not accuracy on a real machine. AI4I is synthetic. Vibration and run hours are not in the data.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string|null>  $header
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $columns = [];
        foreach ($header as $i => $name) {
            $name = preg_replace('/^\xEF\xBB\xBF/', '', (string) $name);
            $name = strtolower(trim((string) preg_replace('/\[.*?\]/', '', (string) $name)));
            $columns[$name] = $i;
        }

        return $columns;
    }

    private function percent(int $part, int $whole): string
    {
        return $whole === 0 ? 'n/a' : sprintf('%.1f%%', 100 * $part / $whole);
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class BacktestCommandTest extends TestCase
{
    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();

        // Five rows in the AI4I layout: a healthy row (UDI 2), a heat failure
        // (UDI 3761), a power failure (UDI 51), a row whose only failure is random
        // and a row labeled as a failure with no failure mode flagged.
        $lines = [
            'UDI,Product ID,Type,Air temperature [K],Process temperature [K],Rotational speed [rpm],Torque [Nm],Tool wear [min],Machine failure,TWF,HDF,PWF,OSF,RNF',
            '2,L47181,L,298.2,308.7,1408,46.3,3,0,0,0,0,0,0',
            '3761,L50940,L,302.3,310.9,1377,46.8,166,1,0,1,0,0,0',
            '51,L47230,L,298.9,309.1,2861,4.6,143,1,0,0,1,0,0',
            '999,M15000,M,300.0,310.0,1540,40.0,20,1,0,0,0,0,1',
            '1000,L15001,L,300.0,310.0,1540,40.0,20,1,0,0,0,0,0',
        ];
        $this->csv = tempnam(sys_get_temp_dir(), 'ai4i').'.csv';
        file_put_contents($this->csv, implode("\n", $lines)."\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    public function test_it_leaves_out_random_only_failures_and_counts_failures_by_band(): void
    {
        $this->artisan('prism:backtest', ['file' => $this->csv])
            ->expectsOutputToContain('Records in file: 5')
            ->expectsOutputToContain('Random failure is the only failure mode: 1')
            ->expectsOutputToContain('Left out (random failure only): 1')
            ->expectsOutputToContain('Records scored: 4')
            ->expectsOutputToContain('Failures among scored records: 3')
            ->expectsOutputToContain('In High: 2 (66.7%)')
            ->expectsOutputToContain('(label only): 1 (Low 1, Elevated 0, High 0)')
            ->assertExitCode(0);
    }

    public function test_keep_random_scores_every_row(): void
    {
        $this->artisan('prism:backtest', ['file' => $this->csv, '--keep-random' => true])
            ->expectsOutputToContain('Left out: 0 (--keep-random)')
            ->expectsOutputToContain('Records scored: 5')
            ->assertExitCode(0);
    }

    public function test_it_fails_on_a_missing_file(): void
    {
        $this->artisan('prism:backtest', ['file' => '/nonexistent/ai4i2020.csv'])
            ->expectsOutputToContain('Cannot read the file')
            ->assertExitCode(1);
    }

    public function test_it_fails_on_a_bad_quality_option(): void
    {
        $this->artisan('prism:backtest', ['file' => $this->csv, '--quality' => 'X'])
            ->expectsOutputToContain('--quality must be')
            ->assertExitCode(1);
    }
}

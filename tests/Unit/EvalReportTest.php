<?php

namespace Tests\Unit;

use App\Services\Ai\EvalReport;
use PHPUnit\Framework\TestCase;

class EvalReportTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(string $source, ?string $reason, array $tools, bool $fix, float $seconds, ?int $in = 400, ?int $out = 60): array
    {
        return [
            'scenario' => 'worn_tool', 'tick' => 3, 'band' => 'high', 'source' => $source, 'reason' => $reason,
            'tools' => $tools, 'tested_fix' => $fix, 'tokens_in' => $in, 'tokens_out' => $out, 'seconds' => $seconds,
        ];
    }

    public function test_it_counts_accepted_fallbacks_and_reasons(): void
    {
        $s = EvalReport::summarize([
            $this->row('ai', null, ['get_learned_estimate', 'what_if'], true, 4.0, 400, 60),
            $this->row('ai', null, ['what_if'], false, 6.0, 600, 80),
            $this->row('fallback', 'ungrounded', ['what_if'], false, 5.0, null, null),
            $this->row('fallback', 'timeout', [], false, 12.0, null, null),
        ]);

        $this->assertSame(4, $s['runs']);
        $this->assertSame(2, $s['accepted']);
        $this->assertSame(2, $s['fallback']);
        $this->assertSame(['timeout' => 1, 'ungrounded' => 1], $s['reasons']);
        $this->assertSame(1, $s['tested_fix']);
        $this->assertSame(1, $s['used_learned']);
        $this->assertSame(3, $s['used_what_if']);
        $this->assertSame(1.0, $s['avg_tools']);
        $this->assertSame(500.0, $s['avg_tokens_in']);
        $this->assertSame(70.0, $s['avg_tokens_out']);
    }

    public function test_percentiles_use_the_nearest_rank(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0];

        $this->assertSame(5.0, EvalReport::percentile($values, 50));
        $this->assertSame(10.0, EvalReport::percentile($values, 95));
        $this->assertSame(0.0, EvalReport::percentile([], 50));
    }

    public function test_an_empty_run_does_not_divide_by_zero(): void
    {
        $s = EvalReport::summarize([]);

        $this->assertSame(0, $s['runs']);
        $this->assertNull($s['avg_tokens_in']);
        $this->assertSame('n/a', EvalReport::percent(0, 0));
    }

    public function test_the_markdown_states_the_counts_and_the_limits(): void
    {
        $s = EvalReport::summarize([$this->row('ai', null, ['what_if'], true, 4.0)]);

        $md = EvalReport::markdown($s, ['date' => 'October 22, 2026', 'model' => 'm', 'every' => 3, 'scenarios' => ['worn_tool']]);

        $this->assertStringContainsString('| Answers accepted by the code checks | 1 of 1 (100.0%) |', $md);
        $this->assertStringContainsString('No technician graded the answers.', $md);
        $this->assertStringContainsString('The readings are simulated.', $md);
    }
}

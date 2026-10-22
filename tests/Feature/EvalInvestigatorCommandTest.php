<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class EvalInvestigatorCommandTest extends TestCase
{
    public function test_a_dry_run_lists_the_plan_and_makes_no_api_call(): void
    {
        Http::fake();
        config(['services.anthropic.key' => 'test-key']);

        $this->artisan('prism:eval-investigator', ['--dry-run' => true, '--scenario' => ['worn_tool'], '--every' => 15])
            ->expectsOutputToContain('Readings in the Elevated or High band')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_stops_without_a_key(): void
    {
        Http::fake();
        config(['services.anthropic.key' => null]);

        $this->artisan('prism:eval-investigator', ['--scenario' => ['worn_tool'], '--every' => 59])
            ->expectsOutputToContain('ANTHROPIC_API_KEY is not set')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_rejects_an_unknown_scenario_and_a_bad_step(): void
    {
        $this->artisan('prism:eval-investigator', ['--scenario' => ['nope']])->assertFailed();
        $this->artisan('prism:eval-investigator', ['--every' => 0])->assertFailed();
    }

    public function test_a_run_counts_the_accepted_answer_and_writes_the_report(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $whatIf = [
            'stop_reason' => 'tool_use',
            'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'what_if', 'input' => ['channel' => 'torque_nm', 'value' => 30.0]]],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 30],
        ];
        $answer = [
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode([
                'cause' => 'Tool wear is rising and the tool is near the failure window.',
                'checks' => ['Pull the tool and look at the cutting edge.'],
                'evidence' => ['what_if'],
            ])]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 50],
        ];
        Http::fake(['api.anthropic.com/*' => Http::sequence()->push($whatIf, 200)->push($answer, 200)]);
        $path = tempnam(sys_get_temp_dir(), 'eval').'.md';

        $this->artisan('prism:eval-investigator', ['--scenario' => ['worn_tool'], '--every' => 59, '--write' => $path])
            ->expectsOutputToContain('Answers accepted by the code checks | 1 of 1 (100.0%)')
            ->assertSuccessful();

        $this->assertStringContainsString('Accepted answers with a tested fix | 1 of 1', (string) file_get_contents($path));
        @unlink($path);
    }

    public function test_the_run_stops_after_three_failed_calls_and_the_log_names_the_api_error(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Log::spy();
        Http::fake(['api.anthropic.com/*' => Http::response(['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'tools.4: bad schema']], 400)]);

        $this->artisan('prism:eval-investigator', ['--scenario' => ['worn_tool'], '--every' => 3])
            ->expectsOutputToContain('Three calls in a row failed')
            ->assertFailed();

        Http::assertSentCount(3);
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c = []) => $m === 'ai_call_failed'
            && ($c['status'] ?? null) === 400
            && ($c['error_type'] ?? null) === 'invalid_request_error'
            && ($c['error_message'] ?? null) === 'tools.4: bad schema')->atLeast()->once();
    }
}

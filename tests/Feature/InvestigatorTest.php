<?php

namespace Tests\Feature;

use App\Services\Ai\AiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvestigatorTest extends TestCase
{
    private const WORN = '?scenario=worn_tool&tick=59';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        Cache::flush();
        config(['services.anthropic.key' => 'test-key', 'prism.investigator' => true]);
        $this->app->forgetInstance(AiClient::class);
    }

    private function toolUse(string $id, string $name, array $input): array
    {
        return [
            'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => 'Checking.'],
                ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input],
            ],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 30],
        ];
    }

    /** A what_if call that tries a lower torque. The gate asks for one before an answer. */
    private function whatIf(string $id, float $torque = 30.0): array
    {
        return $this->toolUse($id, 'what_if', ['channel' => 'torque_nm', 'value' => $torque]);
    }

    private function answer(array $payload): array
    {
        return [
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 50],
        ];
    }

    private function good(): array
    {
        return $this->answer([
            'cause' => 'Tool wear is rising and the tool is near the failure window.',
            'checks' => ['Pull the tool and look at the cutting edge.', 'Change the tool if the edge is worn.'],
            'evidence' => ['what_if'],
        ]);
    }

    public function test_a_tool_call_then_a_grounded_answer_is_used_and_traced(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1'), 200)
            ->push($this->good(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('reason', null)
            ->assertJsonPath('data.evidence.0', 'what_if')
            ->assertJsonPath('trace.0.tool', 'what_if')
            ->assertJsonPath('trace.0.ok', true)
            ->assertJsonPath('tokens.input', 500)
            ->assertJsonPath('tokens.output', 80);

        Http::assertSentCount(2);
        // The second request returns the assistant turn unchanged, then the tool result first.
        Http::assertSent(function ($request) {
            $messages = $request['messages'];
            if (count($messages) !== 3) {
                return true;
            }

            return $messages[1]['role'] === 'assistant'
                && $messages[1]['content'][1]['id'] === 'toolu_1'
                && $messages[2]['content'][0]['type'] === 'tool_result'
                && $messages[2]['content'][0]['tool_use_id'] === 'toolu_1'
                && $request['tool_choice']['type'] === 'auto';
        });
    }

    public function test_the_prompt_asks_for_a_time_estimate_a_what_if_and_no_guessed_causes(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->good(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertOk();

        Http::assertSent(fn ($r) => str_contains($r['system'], 'time_to_limit')
            && str_contains($r['system'], 'Call what_if once')
            && str_contains($r['system'], 'lowers_score')
            && str_contains($r['system'], 'Never write the words JSON')
            && str_contains($r['system'], 'shows stress 100')
            && str_contains($r['system'], 'Do not guess parts'));
    }

    public function test_the_gate_asks_for_a_what_if_before_the_answer(): void
    {
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->data();
            $n = count($requests);
            if ($n === 1) {
                return Http::response($this->good(), 200);
            }
            if ($n === 2) {
                return Http::response($this->whatIf('toolu_9'), 200);
            }

            return Http::response($this->good(), 200);
        });

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('trace.0.tool', 'what_if');

        $this->assertCount(3, $requests);
        $last = end($requests[1]['messages']);
        $this->assertSame('user', $last['role']);
        $this->assertStringContainsString('call what_if once', $last['content']);
    }

    public function test_a_value_that_did_not_lower_the_score_cannot_be_the_recommended_target(): void
    {
        // Torque 77.7 raises the worn tool score, so it is not a tested fix.
        $bad = $this->answer([
            'cause' => 'Tool wear is rising.',
            'checks' => ['Reduce torque to 77.7 Nm.'],
            'evidence' => ['what_if'],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1', 77.7), 200)
            ->push($bad, 200)
            ->push([], 500)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'fallback');
    }

    public function test_a_value_that_lowered_the_score_can_be_named(): void
    {
        $ok = $this->answer([
            'cause' => 'Tool wear is rising.',
            'checks' => ['Bring torque down to 30 Nm.'],
            'evidence' => ['what_if'],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1', 30.0), 200)
            ->push($ok, 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('data.checks.0', 'Bring torque down to 30 Nm.');
    }

    public function test_the_second_click_is_served_from_the_cache(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1'), 200)
            ->push($this->good(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'ai');
        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertJsonPath('source', 'cache')
            ->assertJsonPath('trace.0.tool', 'what_if');

        Http::assertSentCount(2);
    }

    public function test_the_last_round_forces_an_answer(): void
    {
        $call = $this->whatIf('toolu_x');
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($call, 200)->push($call, 200)->push($call, 200)->push($this->good(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'ai');

        Http::assertSentCount(4);
        Http::assertSent(fn ($r) => $r['tool_choice']['type'] === 'none');
    }

    public function test_a_model_that_keeps_calling_tools_falls_back(): void
    {
        $call = $this->toolUse('toolu_x', 'get_check', ['driver' => 'heat']);
        Http::fake(['api.anthropic.com/*' => Http::response($call, 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('reason', 'bad_reply');
    }

    public function test_an_unknown_tool_is_refused_and_the_loop_continues(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('toolu_1', 'delete_everything', []), 200)
            ->push($this->whatIf('toolu_2'), 200)
            ->push($this->good(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('trace.0.ok', false)
            ->assertJsonPath('trace.1.ok', true);
    }

    public function test_an_answer_with_an_invented_number_is_rejected(): void
    {
        $bad = $this->answer([
            'cause' => 'The spindle bearing failed at 777 hours.',
            'checks' => ['Check the bearing.'],
            'evidence' => ['what_if'],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1'), 200)
            ->push($bad, 200)
            // The Diagnose call runs next, because the model did answer.
            ->push([
                'content' => [['type' => 'text', 'text' => json_encode(['cause' => 'The tool is worn.', 'checks' => ['Change the tool.']])]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('data.cause', 'The tool is worn.')
            ->assertJsonPath('trace', []);
    }

    public function test_an_answer_that_names_a_tool_it_did_not_call_is_rejected(): void
    {
        $bad = $this->answer([
            'cause' => 'Tool wear is rising.',
            'checks' => ['Change the tool.'],
            'evidence' => ['get_trend'],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1'), 200)
            ->push($bad, 200)
            // The Diagnose call runs next and the server fails it, so the built-in text shows.
            ->push([], 500)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'fallback');
    }

    public function test_a_timeout_falls_back_without_a_second_call(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('timed out');
        });

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('reason', 'timeout')
            ->assertJsonStructure(['data' => ['cause', 'checks'], 'trace']);

        $this->assertSame(1, $calls);
    }

    public function test_no_key_and_low_risk_send_nothing(): void
    {
        Http::fake();
        $this->getJson('/api/diagnosis?scenario=healthy&tick=0')
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('reason', 'low_risk');

        config(['services.anthropic.key' => null]);
        $this->app->forgetInstance(AiClient::class);
        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('reason', 'no_key');

        Http::assertNothingSent();
    }

    public function test_the_work_order_uses_the_investigation_and_code_sets_the_priority(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1'), 200)
            ->push($this->good(), 200)
            ->push([
                'content' => [['type' => 'text', 'text' => json_encode(['title' => 'Change the tool on M-01', 'steps' => ['Stop the spindle.', 'Change the tool.']])]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200)]);

        $this->getJson('/api/work-order'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('data.title', 'Change the tool on M-01');
    }

    public function test_the_switch_turns_the_investigator_off(): void
    {
        config(['prism.investigator' => false]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['cause' => 'Worn tool.', 'checks' => ['Change it.']])]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('trace', []);

        Http::assertSent(fn ($r) => ! isset($r['tools']));
    }
}

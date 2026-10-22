<?php

namespace Tests\Feature;

use App\Services\Ai\AiClient;
use App\Services\Ai\Investigator;
use App\Services\Ai\ToolBox;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
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
            && str_contains($r['system'], 'straight-line estimate on simulated data')
            && str_contains($r['system'], 'Never write the words JSON')
            && str_contains($r['system'], 'shows stress 100')
            && str_contains($r['system'], 'Do not guess parts'));
    }

    public function test_the_prompt_asks_for_the_learned_estimate_as_a_second_opinion(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->good(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertOk();

        Http::assertSent(fn ($r) => str_contains($r['system'], 'Call get_learned_estimate once')
            && str_contains($r['system'], 'second opinion')
            && in_array('get_learned_estimate', array_column($r['tools'], 'name'), true));
    }

    public function test_a_learned_estimate_call_is_traced_and_its_number_can_be_quoted(): void
    {
        $chance = (new ToolBox('worn_tool', 59))->run('get_learned_estimate', [])['result']['chance_percent'];
        $answer = $this->answer([
            'cause' => 'The trained model puts the chance of failure at '.$chance.' percent. Tool wear is rising.',
            'checks' => ['Pull the tool and look at the cutting edge.'],
            'evidence' => ['get_learned_estimate', 'what_if'],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('toolu_1', 'get_learned_estimate', []), 200)
            ->push($this->whatIf('toolu_2', 30.0), 200)
            ->push($answer, 200)]);

        $res = $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'ai');

        $this->assertSame('get_learned_estimate', $res->json('trace.0.tool'));
        $this->assertStringContainsString((string) $chance, $res->json('data.cause'));
    }

    public function test_a_tool_with_no_arguments_is_sent_back_with_an_empty_object_input(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('toolu_1', 'get_learned_estimate', []), 200)
            ->push($this->whatIf('toolu_2', 30.0), 200)
            ->push($this->good(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertOk();

        // The second request carries Claude's first turn back. Its input must be {} and not [].
        $second = Http::recorded()[1][0];
        $this->assertStringContainsString('"name":"get_learned_estimate","input":{}', $second->body());
        $this->assertStringNotContainsString('"input":[]', $second->body());
    }

    public function test_a_projected_crossing_quoted_in_the_cause_gets_the_straight_line_caveat(): void
    {
        $tick = null;
        $reach = null;
        for ($t = 10; $t <= 58; $t++) {
            $p = (new ToolBox('worn_tool', $t))->run('get_learned_estimate', [])['result']['projection']['first_reading_at_20_percent'] ?? null;
            $band = (new RiskScorer('L'))->score((new SensorSimulator)->reading('worn_tool', $t))['band'];
            if ($p !== null && $band !== 'low') {
                [$tick, $reach] = [$t, $p];
                break;
            }
        }
        $this->assertNotNull($tick, 'No worn_tool reading projects a 20 percent chance.');

        $answer = $this->answer([
            'cause' => "The learned chance reaches 20 percent in {$reach} readings.",
            'checks' => ['Pull the tool and look at the cutting edge.'],
            'evidence' => ['get_learned_estimate', 'what_if'],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('toolu_1', 'get_learned_estimate', []), 200)
            ->push($this->whatIf('toolu_2', 30.0), 200)
            ->push($answer, 200)]);

        $res = $this->getJson('/api/diagnosis?scenario=worn_tool&tick='.$tick)->assertJsonPath('source', 'ai');

        $this->assertStringEndsWith('straight-line estimate on simulated data.', $res->json('data.cause'));
    }

    public function test_an_invented_learned_chance_is_rejected(): void
    {
        $real = (new ToolBox('worn_tool', 59))->run('get_learned_estimate', [])['result']['chance_percent'];
        $invented = $real > 50 ? 7.3 : 83.7;
        $bad = $this->answer([
            'cause' => 'The trained model puts the chance of failure at '.$invented.' percent.',
            'checks' => ['Pull the tool.'],
            'evidence' => ['get_learned_estimate'],
        ]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->toolUse('toolu_1', 'get_learned_estimate', []), 200)
            ->push($this->whatIf('toolu_2', 30.0), 200)
            ->push($bad, 200)
            ->push([
                'content' => [['type' => 'text', 'text' => json_encode(['cause' => 'The tool is worn.', 'checks' => ['Change the tool.']])]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ], 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('data.cause', 'The tool is worn.')
            ->assertJsonPath('trace', []);
    }

    public function test_the_tested_fix_sentence_shows_how_the_learned_chance_moves(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1', 30.0), 200)
            ->push($this->good(), 200)]);

        $res = $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'ai');

        $this->assertMatchesRegularExpression('/The learned estimate of failure (moves from [\d.]+% to [\d.]+%|stays at [\d.]+%)/', $res->json('data.tested_fix.text'));
        $this->assertStringContainsString('This is a test only', $res->json('data.tested_fix.text'));
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

    public function test_a_what_if_that_lowers_the_score_becomes_a_tested_fix_sentence(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1', 30.0), 200)
            ->push($this->good(), 200)]);

        $res = $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'ai');

        $fix = $res->json('data.tested_fix');
        $this->assertSame('torque_nm', $fix['channel']);
        $this->assertLessThan($fix['score_from'], $fix['score_to']);
        $this->assertStringContainsString('torque set to 30 Nm', $fix['text']);
        $this->assertStringContainsString('This is a test only', $fix['text']);
    }

    public function test_no_tested_fix_when_the_what_if_did_not_lower_the_score(): void
    {
        $ok = $this->answer(['cause' => 'Tool wear is rising.', 'checks' => ['Inspect the tool.'], 'evidence' => ['what_if']]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->whatIf('toolu_1', 77.7), 200)
            ->push($ok, 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('data.tested_fix', null);
    }

    public function test_code_adds_the_straight_line_caveat_to_a_quoted_estimate(): void
    {
        $rising = [['status' => 'rising', 'readings_to_limit' => 5, 'minutes_to_limit' => 150]];

        $this->assertStringEndsWith(
            'straight-line estimate on simulated data.',
            Investigator::addCaveat('Power reaches its top in 150 minutes.', $rising),
        );
        // Already said, so no second sentence.
        $said = 'About 150 minutes, a straight-line estimate.';
        $this->assertSame($said, Investigator::addCaveat($said, $rising));
        // The estimate is not quoted, so nothing is added.
        $plain = 'Power is high.';
        $this->assertSame($plain, Investigator::addCaveat($plain, $rising));
        // A driver that is not rising has no estimate to quote.
        $flat = [['status' => 'not_rising']];
        $this->assertSame('It stays at 150.', Investigator::addCaveat('It stays at 150.', $flat));
    }

    public function test_a_percentage_that_matches_the_reading_count_does_not_add_the_caveat(): void
    {
        // The screenshot case: "4%" next to a time estimate of 4 readings.
        $rising = [['status' => 'rising', 'readings_to_limit' => 4, 'minutes_to_limit' => 120]];
        $cause = 'The learned model gives only a 4% chance, which disagrees with the high band.';

        $this->assertSame($cause, Investigator::addCaveat($cause, $rising));
        // A number with a time unit still adds it, in readings, minutes or hours.
        foreach (['It reaches the limit in about 4 readings.', 'It reaches the limit in 120 minutes.', 'It reaches the limit in 2 hours.'] as $quoted) {
            $this->assertStringEndsWith('straight-line estimate on simulated data.', Investigator::addCaveat($quoted, $rising));
        }
    }

    public function test_the_tested_fix_says_so_when_the_rules_and_the_model_disagree(): void
    {
        $base = ['channel' => 'torque_nm', 'tried_value' => 40, 'current_score' => 76, 'score_if_changed' => 60, 'band_if_changed' => 'elevated'];

        $worse = Investigator::testedFix($base + ['learned_chance_percent_now' => 4.0, 'learned_chance_percent_if_changed' => 4.6]);
        $this->assertStringContainsString('moves from 4% to 4.6%, so the rules and the model disagree on this change. Inspect before relying on it.', $worse['text']);

        $better = Investigator::testedFix($base + ['learned_chance_percent_now' => 4.6, 'learned_chance_percent_if_changed' => 2.1]);
        $this->assertStringContainsString('moves from 4.6% to 2.1%.', $better['text']);
        $this->assertStringNotContainsString('disagree', $better['text']);

        $same = Investigator::testedFix($base + ['learned_chance_percent_now' => 4.0, 'learned_chance_percent_if_changed' => 4.0]);
        $this->assertStringContainsString('stays at 4%.', $same['text']);

        $none = Investigator::testedFix($base);
        $this->assertStringNotContainsString('learned estimate', $none['text']);
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

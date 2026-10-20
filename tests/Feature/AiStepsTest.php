<?php

namespace Tests\Feature;

use App\Services\Ai\AiClient;
use App\Services\Ai\Diagnoser;
use App\Services\RiskScorer;
use App\Services\SensorSimulator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiStepsTest extends TestCase
{
    private const WORN = '?scenario=worn_tool&tick=59';

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
        Cache::flush();
        config(['services.anthropic.key' => 'test-key']);
        // The client is a singleton, so rebuild it after the config change.
        $this->app->forgetInstance(AiClient::class);
    }

    /** The body of an Anthropic Messages API reply that carries one JSON text block. */
    private function body(array $payload, int $in = 180, int $out = 40): array
    {
        return [
            'content' => [['type' => 'text', 'text' => json_encode($payload)]],
            'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
        ];
    }

    private function diagnosisBody(): array
    {
        return $this->body(['cause' => 'The tool is worn and torque is high.', 'checks' => ['Change the tool.', 'Check the torque load.']]);
    }

    public function test_diagnosis_uses_the_ai_reply_and_reports_tokens(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->diagnosisBody(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('data.cause', 'The tool is worn and torque is high.')
            ->assertJsonPath('data.checks.0', 'Change the tool.')
            ->assertJsonPath('tokens.input', 180)
            ->assertJsonPath('tokens.output', 40);

        Http::assertSent(fn ($request) => $request->hasHeader('x-api-key', 'test-key')
            // 220 output tokens plus 1,024 of thinking headroom added by AiClient.
            && $request['max_tokens'] === 220 + 1024
            && strlen(json_encode($request['messages'])) < 2000);
    }

    public function test_the_prompt_input_stays_small(): void
    {
        $reading = (new SensorSimulator)->reading('worn_tool', 59);
        $input = Diagnoser::input((new RiskScorer('L'))->score($reading));

        $this->assertLessThan(1500, strlen(json_encode($input)));
    }

    public function test_second_request_is_served_from_the_cache(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->diagnosisBody(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'ai');
        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'cache');

        Http::assertSentCount(1);
    }

    public function test_no_key_sends_no_request_and_shows_the_built_in_text(): void
    {
        config(['services.anthropic.key' => null]);
        $this->app->forgetInstance(AiClient::class);
        Http::fake();

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('tokens', null);

        Http::assertNothingSent();
    }

    public function test_a_low_risk_reading_makes_no_ai_call(): void
    {
        Http::fake();

        $this->getJson('/api/diagnosis?scenario=healthy&tick=0')
            ->assertOk()
            ->assertJsonPath('source', 'fallback');

        Http::assertNothingSent();
    }

    public function test_a_server_error_falls_back(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertJsonStructure(['data' => ['cause', 'checks']]);
    }

    public function test_a_timeout_falls_back(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'fallback');
    }

    public function test_a_reply_that_is_not_json_falls_back(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Sorry, I cannot help.']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('source', 'fallback');
    }

    public function test_a_json_reply_in_a_code_fence_still_parses(): void
    {
        $json = json_encode(['cause' => 'Heat is building up.', 'checks' => ['Check coolant flow.']]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => "```json\n{$json}\n```"]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('data.cause', 'Heat is building up.');
    }

    public function test_work_order_priority_comes_from_code_not_the_model(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->diagnosisBody(), 200)
            ->push($this->body([
                'title' => 'Replace worn tool on M-01',
                'priority' => 'Low, ignore this',
                'steps' => ['Stop the machine.', 'Change the tool.', 'Check torque.'],
            ], 200, 60), 200),
        ]);

        $this->getJson('/api/work-order'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'ai')
            ->assertJsonPath('data.title', 'Replace worn tool on M-01')
            ->assertJsonPath('data.priority', 'Urgent: stop the machine and inspect now')
            ->assertJsonCount(3, 'data.steps');
    }

    public function test_work_order_falls_back_when_the_ai_is_down(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 503)]);

        $this->getJson('/api/work-order'.self::WORN)
            ->assertOk()
            ->assertJsonPath('source', 'fallback')
            ->assertJsonPath('data.priority', 'Urgent: stop the machine and inspect now')
            ->assertJsonStructure(['data' => ['title', 'priority', 'steps']]);
    }

    public function test_a_server_error_reports_api_error(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('reason', 'api_error');
    }

    public function test_a_timeout_reports_timeout(): void
    {
        Http::fake(function () {
            throw new ConnectionException('timed out');
        });

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('reason', 'timeout');
    }

    public function test_a_reply_that_is_not_json_reports_bad_reply(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Sorry, I cannot help.']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ], 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('reason', 'bad_reply');
    }

    public function test_a_low_risk_reading_reports_low_risk(): void
    {
        Http::fake();

        $this->getJson('/api/diagnosis?scenario=healthy&tick=0')->assertJsonPath('reason', 'low_risk');
    }

    public function test_no_key_reports_no_key(): void
    {
        config(['services.anthropic.key' => null]);
        $this->app->forgetInstance(AiClient::class);
        Http::fake();

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('reason', 'no_key');
    }

    public function test_an_ai_answer_reports_no_reason(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->diagnosisBody(), 200)]);

        $this->getJson('/api/diagnosis'.self::WORN)->assertJsonPath('reason', null);
    }

    public function test_the_built_in_work_order_never_repeats_a_step(): void
    {
        config(['services.anthropic.key' => null]);
        $this->app->forgetInstance(AiClient::class);
        Http::fake();

        $steps = $this->getJson('/api/work-order'.self::WORN)->assertOk()->json('data.steps');

        $this->assertGreaterThanOrEqual(2, count($steps));
        $this->assertSame($steps, array_values(array_unique($steps)));
    }

    public function test_the_built_in_cause_leaves_the_instruction_to_the_steps(): void
    {
        config(['services.anthropic.key' => null]);
        $this->app->forgetInstance(AiClient::class);
        Http::fake();

        $checks = $this->getJson('/api/diagnosis'.self::WORN)->assertOk()->json('data.checks');
        $this->assertNotEmpty($checks);

        $reading = (new SensorSimulator)->reading('worn_tool', 59);
        $this->assertStringNotContainsString('Plan a tool change', (new RiskScorer('L'))->score($reading)['explanation']);
    }

    public function test_bad_input_is_rejected(): void
    {
        Http::fake();

        $this->getJson('/api/diagnosis?scenario=nope&tick=1')->assertStatus(422);
        $this->getJson('/api/work-order?scenario=worn_tool&tick=-5')->assertStatus(422);

        Http::assertNothingSent();
    }
}

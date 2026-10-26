<?php

namespace Tests\Feature;

use App\Models\AiFeedback;
use App\Models\OverrideLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every API route that takes data rejects input outside its bounds with
 * HTTP 400 and stores nothing. The checks: type, string length, number range,
 * allowed values and arrays. No API route takes a date or a boolean.
 */
class ApiValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    /** @return array<string, mixed> */
    private function override(array $with = []): array
    {
        return array_merge([
            'operator_name' => 'Pat Rivera',
            'reason' => 'Finishing the last part of the shift with a supervisor watching.',
            'scenario' => 'overload',
            'tick' => 59,
        ], $with);
    }

    /** @return array<string, mixed> */
    private function rating(array $with = []): array
    {
        return array_merge([
            'step' => 'diagnosis', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'overload', 'tick' => 59,
        ], $with);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badOverrides(): array
    {
        return [
            'name missing' => [['operator_name' => null]],
            'name too short' => [['operator_name' => 'P']],
            'name too long' => [['operator_name' => str_repeat('a', 101)]],
            'name is an array' => [['operator_name' => ['Pat']]],
            'name is a number' => [['operator_name' => ['a' => 1]]],
            'reason missing' => [['reason' => null]],
            'reason too short' => [['reason' => 'too short']],
            'reason too long' => [['reason' => str_repeat('a', 501)]],
            'reason is an array' => [['reason' => ['one', 'two']]],
            'scenario not allowed' => [['scenario' => 'nope']],
            'scenario is an array' => [['scenario' => ['overload']]],
            'tick missing' => [['tick' => null]],
            'tick is text' => [['tick' => 'abc']],
            'tick is a decimal' => [['tick' => 1.5]],
            'tick below zero' => [['tick' => -1]],
            'tick above the limit' => [['tick' => 1000001]],
            'tick is an array' => [['tick' => [1, 2]]],
            'tick is a boolean' => [['tick' => true]],
            'name is a boolean' => [['operator_name' => true]],
        ];
    }

    #[DataProvider('badOverrides')]
    public function test_the_override_route_rejects_bad_input_with_400(array $with): void
    {
        $this->postJson('/api/overrides', $this->override($with))
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors']);

        $this->assertSame(0, OverrideLog::count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badRatings(): array
    {
        return [
            'step not allowed' => [['step' => 'other']],
            'step is an array' => [['step' => ['diagnosis']]],
            'rating not allowed' => [['rating' => 'terrible machine, call me at 555-0100']],
            'rating missing' => [['rating' => null]],
            'source not allowed' => [['source' => 'user']],
            'scenario not allowed' => [['scenario' => 'nope']],
            'tick is text' => [['tick' => 'abc']],
            'tick below zero' => [['tick' => -1]],
            'tick above the limit' => [['tick' => 1000001]],
            'tick is an array' => [['tick' => [1]]],
            'tick is a boolean' => [['tick' => true]],
        ];
    }

    #[DataProvider('badRatings')]
    public function test_the_rating_route_rejects_bad_input_with_400(array $with): void
    {
        $this->postJson('/api/ai-feedback', $this->rating($with))
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors']);

        $this->assertSame(0, AiFeedback::count());
    }

    /** @return array<string, array{0: string}> */
    public static function readingRoutes(): array
    {
        return [['/api/readings'], ['/api/diagnosis'], ['/api/work-order']];
    }

    #[DataProvider('readingRoutes')]
    public function test_the_reading_routes_reject_bad_query_values_with_400(string $route): void
    {
        foreach ([
            'scenario=nope&tick=1',
            'scenario[]=overload&tick=1',
            'tick=1',
            'scenario=overload',
            'scenario=overload&tick=abc',
            'scenario=overload&tick=1.5',
            'scenario=overload&tick=-1',
            'scenario=overload&tick=1000001',
            'scenario=overload&tick[]=1',
        ] as $query) {
            $this->getJson($route.'?'.$query)->assertStatus(400)->assertJsonStructure(['message', 'errors']);
        }
    }

    public function test_a_good_value_at_each_limit_is_accepted(): void
    {
        $this->getJson('/api/readings?scenario=overload&tick=0')->assertOk();
        $this->getJson('/api/readings?scenario=overload&tick=1000000')->assertOk();
        $this->postJson('/api/ai-feedback', $this->rating(['tick' => 1000000]))->assertCreated();
        $this->postJson('/api/overrides', $this->override([
            'operator_name' => str_repeat('a', 100),
            'reason' => str_repeat('b', 500),
        ]))->assertCreated();
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_expired_renders_a_styled_419(): void
    {
        Route::get('/__abort-419', fn () => abort(419));

        $this->get('/__abort-419')
            ->assertStatus(419)
            ->assertSee('Page Expired')
            ->assertSee('Return to Dashboard');
    }

    public function test_rate_limit_renders_a_styled_429_with_the_wait(): void
    {
        Route::get('/__throttled', fn () => 'ok')->middleware('throttle:1,1');

        $this->get('/__throttled')->assertOk();
        $response = $this->get('/__throttled');

        $response->assertStatus(429)
            ->assertSee('Too Many Requests')
            ->assertSee('Try again in')
            ->assertSee('Return to Dashboard');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_rate_limited_json_requests_still_get_json(): void
    {
        Route::get('/__throttled-json', fn () => ['ok' => true])->middleware('throttle:1,1');

        $this->getJson('/__throttled-json')->assertOk();
        $this->getJson('/__throttled-json')->assertStatus(429)->assertJsonStructure(['message']);
    }

    public function test_server_error_page_hides_the_exception_message(): void
    {
        config(['app.debug' => false]);
        Route::get('/__boom', function () {
            throw new \RuntimeException('secret database detail');
        });

        $response = $this->get('/__boom');

        $response->assertStatus(500)
            ->assertSee('Something Went Wrong')
            ->assertSee('Return to Dashboard')
            ->assertDontSee('secret database detail')
            ->assertDontSee('RuntimeException');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_server_error_json_request_gets_json_without_the_message(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/__boom', function () {
            throw new \RuntimeException('secret database detail');
        });

        $this->getJson('/api/__boom')
            ->assertStatus(500)
            ->assertDontSee('secret database detail');
    }

    public function test_production_hides_the_trace_even_if_debug_is_left_on(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => true]);
        Route::get('/__boom-page', fn () => throw new \RuntimeException('secret database detail'));
        Route::get('/api/__boom-prod', fn () => throw new \RuntimeException('secret database detail'));

        $page = $this->get('/__boom-page');
        $page->assertStatus(500)->assertSee('Something Went Wrong')->assertDontSee('secret database detail');
        $this->assertStringNotContainsString(base_path(), $page->getContent());

        $json = $this->getJson('/api/__boom-prod');
        $json->assertStatus(500);
        $this->assertStringNotContainsString('secret database detail', $json->getContent());
        $this->assertStringNotContainsString(base_path(), $json->getContent());
    }

    public function test_api_errors_are_generic_whatever_went_wrong(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/__db', fn () => DB::select('select * from no_such_table_xyz'));
        Route::get('/api/__model', fn () => User::findOrFail(999999));
        Route::post('/api/__post-only', fn () => 'x');

        $db = $this->getJson('/api/__db');
        $db->assertStatus(500)->assertJsonStructure(['message', 'reference']);
        $this->assertSame(['message', 'reference'], array_keys($db->json()));
        foreach (['no_such_table_xyz', 'select', 'sqlite', 'SQLSTATE', 'QueryException', base_path(), 'trace', '.php'] as $leak) {
            $this->assertStringNotContainsString($leak, $db->getContent());
        }

        $model = $this->getJson('/api/__model');
        $model->assertStatus(404)->assertExactJson(['message' => 'Not found.']);

        $wrongMethod = $this->deleteJson('/api/__post-only');
        $wrongMethod->assertStatus(405);
        $this->assertStringNotContainsString('DELETE', $wrongMethod->getContent());
        $this->assertStringNotContainsString('/api/__post-only', $wrongMethod->getContent());

        $missing = $this->getJson('/api/does-not-exist');
        $missing->assertStatus(404)->assertExactJson(['message' => 'Not found.']);
    }

    public function test_the_reference_in_the_response_is_in_the_server_log_with_the_full_error(): void
    {
        $captured = [];
        Log::listen(function ($event) use (&$captured) {
            $captured[] = ['message' => $event->message, 'context' => $event->context];
        });
        Route::get('/api/__boom-ref', fn () => throw new \RuntimeException('secret database detail'));

        $reference = $this->getJson('/api/__boom-ref')->assertStatus(500)->json('reference');

        $this->assertNotEmpty($reference);
        $hit = collect($captured)->first(fn ($row) => ($row['context']['error_reference'] ?? null) === $reference);
        $this->assertNotNull($hit);
        $this->assertStringContainsString('secret database detail', $hit['message']);
    }

    public function test_an_error_page_script_is_on_every_page_and_has_no_console_calls(): void
    {
        $this->get('/faq')->assertSee('js/error-boundary.js', false);
        $js = file_get_contents(public_path('js/error-boundary.js'));
        $this->assertDoesNotMatchRegularExpression('/console\s*\./', $js);
    }

    public function test_maintenance_page_renders_a_styled_503(): void
    {
        Route::get('/__abort-503', fn () => abort(503));

        $this->get('/__abort-503')
            ->assertStatus(503)
            ->assertSee('Back Soon')
            ->assertSee('Return to Dashboard');
    }

    public function test_every_error_page_carries_the_request_nonce_on_its_style_tag(): void
    {
        Route::get('/__abort-419b', fn () => abort(419));

        $response = $this->get('/__abort-419b');
        $this->assertSame(1, preg_match("/style-src 'self' 'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m));
        $this->assertStringContainsString('<style nonce="'.$m[1].'">', $response->getContent());
    }
}

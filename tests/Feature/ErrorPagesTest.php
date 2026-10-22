<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
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
        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $m));
        $this->assertStringContainsString('<style nonce="'.$m[1].'">', $response->getContent());
    }
}

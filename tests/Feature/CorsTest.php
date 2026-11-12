<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORS allowlist: only PRISM's own site, only the methods the API uses, and
 * no credentials. Laravel's framework default allows every origin.
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://prismcua.duckdns.org';

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => false]);
    }

    public function test_the_config_is_not_a_wildcard_and_sends_no_credentials(): void
    {
        $this->assertSame([self::SITE], config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_origins'));
        $this->assertNotContains('*', config('cors.allowed_methods'));
        $this->assertNotContains('*', config('cors.allowed_headers'));
        $this->assertSame(['GET', 'POST', 'PUT', 'DELETE'], config('cors.allowed_methods'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_the_site_origin_gets_an_allow_header_on_the_api(): void
    {
        $this->getJson('/api/readings', ['Origin' => self::SITE])
            ->assertHeader('Access-Control-Allow-Origin', self::SITE)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_another_origin_is_never_echoed_back(): void
    {
        // With one allowed origin the CORS library always answers with that
        // origin. A browser then blocks the page on another site, because the
        // header does not match the page's origin.
        $response = $this->getJson('/api/readings', ['Origin' => 'https://evil.example']);

        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_preflight_from_another_origin_is_not_approved(): void
    {
        $response = $this->call('OPTIONS', '/api/overrides', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_a_preflight_for_a_method_the_api_does_not_use_is_refused(): void
    {
        $response = $this->call('OPTIONS', '/api/overrides', [], [], [], [
            'HTTP_ORIGIN' => self::SITE,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
        ]);

        $this->assertStringNotContainsString('PATCH', (string) $response->headers->get('Access-Control-Allow-Methods'));
    }
}

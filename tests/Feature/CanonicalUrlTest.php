<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectTrailingSlash;
use Illuminate\Http\Request;
use Tests\TestCase;

class CanonicalUrlTest extends TestCase
{
    public function test_canonical_drops_query_string(): void
    {
        $html = $this->get('/faq?utm_source=x&ref=y')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/faq').'">', $html);
    }

    public function test_trailing_slash_redirects_permanently(): void
    {
        // The test client trims trailing slashes from the URL, so call the
        // middleware with a raw request instead.
        $middleware = new RedirectTrailingSlash;
        $next = fn () => response('ok');

        $response = $middleware->handle(Request::create('http://localhost/faq/', 'GET'), $next);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/faq', parse_url($response->headers->get('Location'), PHP_URL_PATH));

        $response = $middleware->handle(Request::create('http://localhost/faq/?a=1', 'GET'), $next);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/faq', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->assertSame('a=1', parse_url($response->headers->get('Location'), PHP_URL_QUERY));
    }

    public function test_canonical_uses_configured_app_url_host(): void
    {
        config(['app.url' => 'https://prism.example.com']);

        $html = $this->get('/privacy')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="https://prism.example.com/privacy">', $html);

        $this->get('/sitemap.xml')
            ->assertSee('<loc>https://prism.example.com/faq</loc>', false)
            ->assertSee('<loc>https://prism.example.com</loc>', false);
    }

    public function test_api_readings_is_marked_noindex(): void
    {
        $this->getJson('/api/readings?scenario=worn_tool&tick=0')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex');
    }
}

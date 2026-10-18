<?php

namespace Tests\Feature;

use Tests\TestCase;

class CanonicalUrlTest extends TestCase
{
    public function test_canonical_drops_query_string(): void
    {
        $html = $this->get('/faq?utm_source=x&ref=y')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/faq">', $html);
    }

    public function test_trailing_slash_redirects_permanently(): void
    {
        $this->get('/faq/')->assertStatus(301)->assertRedirect('/faq');
        $this->get('/faq/?a=1')->assertStatus(301)->assertRedirect('/faq?a=1');
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

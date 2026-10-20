<?php

namespace Tests\Feature;

use Tests\TestCase;

class SitemapTest extends TestCase
{
    public function test_sitemap_lists_public_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        foreach (['/ai', '/faq', '/privacy', '/terms'] as $path) {
            $response->assertSee(url($path), false);
        }
    }
}

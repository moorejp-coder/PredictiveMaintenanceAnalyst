<?php

namespace Tests\Feature;

use Tests\TestCase;

class NotFoundTest extends TestCase
{
    public function test_unknown_page_renders_custom_404(): void
    {
        $response = $this->get('/this-page-does-not-exist');

        $response->assertNotFound();
        $response->assertSee('Page not found');
        $response->assertSee(url('/'), false);
    }

    public function test_unknown_api_route_still_returns_json(): void
    {
        $this->getJson('/api/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
    }
}

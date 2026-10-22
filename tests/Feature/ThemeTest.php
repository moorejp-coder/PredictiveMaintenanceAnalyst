<?php

namespace Tests\Feature;

use Tests\TestCase;

class ThemeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function test_every_page_with_the_header_has_a_theme_switch(): void
    {
        foreach (['/', '/ai', '/faq'] as $path) {
            $this->get($path)->assertOk()->assertSee('id="theme-toggle"', false);
        }
    }

    public function test_pages_apply_a_saved_theme_before_paint(): void
    {
        foreach (['/', '/ai', '/faq', '/privacy', '/terms'] as $path) {
            $this->get($path)->assertOk()->assertSee('js/theme-init.js', false);
        }

        $this->assertStringContainsString("localStorage.getItem('prism-theme')", file_get_contents(public_path('js/theme-init.js')));
    }

    public function test_the_stylesheet_defines_light_tokens_for_both_triggers(): void
    {
        $css = file_get_contents(public_path('css/prism.css'));

        $this->assertStringContainsString(':root[data-theme="light"]', $css);
        $this->assertStringContainsString('@media (prefers-color-scheme: light)', $css);
        $this->assertStringContainsString(':root:not([data-theme="dark"])', $css);
    }

    public function test_the_chart_reads_its_colors_from_theme_tokens(): void
    {
        $this->get('/')->assertOk()->assertDontSee('stroke="#26355A"', false);
        $this->assertStringContainsString("themeColor('--prism-risk-high')", file_get_contents(public_path('js/dashboard.js')));
    }
}

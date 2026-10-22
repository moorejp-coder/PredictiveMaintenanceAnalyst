<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Explain this reading pop-up freezes the simulated feed so the answer
 * matches the reading on screen. The behavior itself runs in the browser. These
 * tests check the page ships the hooks and the notice, and the browser check
 * is described in the README.
 */
class ExplainPausesFeedTest extends TestCase
{
    public function test_the_dashboard_exposes_hold_and_release(): void
    {
        $this->get('/')->assertOk();
        $html = file_get_contents(public_path('js/dashboard.js')).$this->get('/')->getContent();

        $this->assertStringContainsString('window.prismFeed = {', $html);
        $this->assertStringContainsString('hold() {', $html);
        $this->assertStringContainsString('release() {', $html);
        $this->assertStringContainsString('state.paused || state.held', $html);
        $this->assertStringContainsString('Paused while the explanation is open', $html);
    }

    public function test_the_explain_panel_holds_before_reading_and_releases_on_close(): void
    {
        $html = file_get_contents(public_path('js/ai-panel.js')).$this->get('/')->assertOk()->getContent();

        $hold = strpos($html, 'window.prismFeed.hold()');
        $read = strpos($html, 'const latest = window.prismLatest;');
        $this->assertNotFalse($hold);
        $this->assertNotFalse($read);
        $this->assertLessThan($read, $hold, 'The feed must be held before the reading is read.');

        $this->assertStringContainsString('window.prismFeed.release()', $html);
        $this->assertStringContainsString('The simulated feed is paused while this pop-up is open.', $html);
    }
}

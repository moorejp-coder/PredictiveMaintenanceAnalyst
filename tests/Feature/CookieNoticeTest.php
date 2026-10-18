<?php

namespace Tests\Feature;

use Tests\TestCase;

class CookieNoticeTest extends TestCase
{
    public function test_every_page_includes_the_cookie_notice(): void
    {
        foreach (['/', '/faq', '/privacy', '/terms'] as $path) {
            $this->get($path)->assertOk()->assertSee('id="cookie-notice"', false);
        }
    }

    public function test_notice_names_the_essential_cookies_only(): void
    {
        $this->get('/')->assertSee('sets no analytics, advertising or tracking cookies');
    }

    public function test_privacy_page_offers_to_reopen_the_notice(): void
    {
        $this->get('/privacy')->assertSee('Show the cookie notice again');
    }
}

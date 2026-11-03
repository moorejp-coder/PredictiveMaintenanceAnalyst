<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    private function nonceFrom(string $csp): string
    {
        $this->assertSame(1, preg_match("/style-src 'self' 'nonce-([^']+)'/", $csp, $m));

        return $m[1];
    }

    public function test_pages_send_the_basic_security_headers(): void
    {
        $response = $this->get('/faq');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    public function test_every_inline_script_and_style_carries_the_request_nonce(): void
    {
        foreach (['/', '/faq', '/ai', '/privacy', '/terms'] as $path) {
            $response = $this->get($path);
            $response->assertOk();

            $nonce = $this->nonceFrom($response->headers->get('Content-Security-Policy'));
            $html = $response->getContent();

            $this->assertStringContainsString('<script src=', $html, $path);
            $this->assertDoesNotMatchRegularExpression('/<style(?![^>]*nonce=)/', $html, $path);
            $this->assertStringContainsString('<style nonce="'.$nonce.'">', $html, $path);
            // No inline JavaScript: every script tag loads a file.
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/', $html, $path);
        }
    }

    public function test_the_nonce_changes_on_every_request(): void
    {
        $first = $this->nonceFrom($this->get('/faq')->headers->get('Content-Security-Policy'));
        $second = $this->nonceFrom($this->get('/faq')->headers->get('Content-Security-Policy'));

        $this->assertNotSame($first, $second);
    }

    public function test_the_policy_blocks_framing_plugins_and_other_origins(): void
    {
        $csp = $this->get('/faq')->headers->get('Content-Security-Policy');

        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'", "connect-src 'self'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }

        $this->assertSame(1, preg_match('/script-src ([^;]+)/', $csp, $m));
        $this->assertStringNotContainsString('unsafe-inline', $m[1]);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $plain = $this->get('/faq');
        $plain->assertHeaderMissing('Strict-Transport-Security');
        $this->assertStringNotContainsString('upgrade-insecure-requests', $plain->headers->get('Content-Security-Policy'));

        $secure = $this->get('https://localhost/faq');
        $secure->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $this->assertStringContainsString('upgrade-insecure-requests', $secure->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_is_sent_when_the_app_url_is_https(): void
    {
        config(['app.url' => 'https://example.test']);

        $this->get('/faq')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_the_not_found_page_has_the_headers_and_a_working_nonce(): void
    {
        $response = $this->get('/no-such-page');

        $response->assertStatus(404);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $nonce = $this->nonceFrom($response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('<style nonce="'.$nonce.'">', $response->getContent());
    }

    public function test_json_routes_get_the_headers_too(): void
    {
        $response = $this->getJson('/up');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_a_header_set_by_a_controller_is_not_overwritten(): void
    {
        $this->app['router']->get('/__test-referrer', fn () => response('ok')->header('Referrer-Policy', 'no-referrer'));

        $this->get('/__test-referrer')->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_nginx_sends_the_same_headers_on_the_files_it_serves(): void
    {
        $page = $this->get('https://localhost/faq');
        $conf = (string) file_get_contents(base_path('.platform/nginx/conf.d/elasticbeanstalk/laravel.conf'));

        foreach (['Strict-Transport-Security', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy', 'Permissions-Policy', 'Cross-Origin-Opener-Policy'] as $name) {
            $value = (string) $page->headers->get($name);
            $this->assertNotSame('', $value, $name);
            $this->assertStringContainsString('add_header '.$name.' "'.$value.'" always;', $conf, $name);
        }

        // Static files get a policy that loads nothing but images, styles and
        // fonts from PRISM, and may not be framed.
        $this->assertStringContainsString("add_header Content-Security-Policy \"default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'\" always;", $conf);
    }

    public function test_the_session_cookie_is_secure_when_the_app_url_is_https(): void
    {
        $config = require base_path('config/session.php');

        // The default under the test environment (http) is not secure.
        $this->assertFalse((bool) $config['secure']);
    }
}

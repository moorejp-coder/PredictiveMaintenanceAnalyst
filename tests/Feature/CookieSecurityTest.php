<?php

namespace Tests\Feature;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class CookieSecurityTest extends TestCase
{
    private function sessionCookie(TestResponse $response): Cookie
    {
        $cookie = $response->getCookie((string) config('session.cookie'), false);
        $this->assertNotNull($cookie, 'The session cookie was not set.');

        return $cookie;
    }

    public function test_the_session_cookie_is_the_only_cookie(): void
    {
        $response = $this->get('/faq')->assertOk();

        $names = array_map(fn (Cookie $c) => $c->getName(), $response->headers->getCookies());

        $this->assertSame([(string) config('session.cookie')], $names);
        $response->assertCookieMissing('XSRF-TOKEN');
    }

    public function test_the_session_cookie_is_http_only_lax_and_short_lived(): void
    {
        $cookie = $this->sessionCookie($this->get('/faq'));

        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());

        // Expires 30 minutes after the last request (SESSION_LIFETIME).
        $left = $cookie->getExpiresTime() - time();
        $this->assertGreaterThan(29 * 60, $left);
        $this->assertLessThanOrEqual(30 * 60, $left);
    }

    public function test_the_session_cookie_is_secure_when_https_is_on(): void
    {
        config(['session.secure' => true]);

        $this->assertTrue($this->sessionCookie($this->get('https://localhost/faq'))->isSecure());
    }

    public function test_the_session_cookie_holds_an_encrypted_id_not_session_data(): void
    {
        $cookie = $this->sessionCookie($this->get('/faq'));
        $raw = base64_decode($cookie->getValue(), true);

        // Laravel encrypts cookie values: base64 JSON with iv, value and mac.
        $this->assertIsString($raw);
        $payload = json_decode($raw, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('mac', $payload);
    }
}

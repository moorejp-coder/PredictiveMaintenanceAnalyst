<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SafeRedirect;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Every redirect stays on PRISM: the trailing-slash redirect, the page after
 * sign-in and back() after a failed form.
 */
class OpenRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => true, 'app.url' => 'http://localhost']);
    }

    private function judge(): User
    {
        return User::factory()->create([
            'email' => 'judge@example.test',
            'password' => $this->fixturePassword('correct'),
        ]);
    }

    /**
     * Sends the path exactly as a browser would, without the test client
     * reading "//host" as a host. nginx passes the raw path the same way.
     */
    private function rawGet(string $uri): Response
    {
        $request = new Request([], [], [], [], [], [
            'REQUEST_URI' => $uri,
            'REQUEST_METHOD' => 'GET',
            'HTTP_HOST' => 'localhost',
            'SERVER_PORT' => 80,
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => base_path('public/index.php'),
            'QUERY_STRING' => (string) parse_url('http://x'.$uri, PHP_URL_QUERY),
        ]);

        return $this->app->make(Kernel::class)->handle($request);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function trailingSlashPaths(): array
    {
        return [
            'double slash host' => ['//evil.example/', 'http://localhost/evil.example'],
            'double slash host with path and query' => ['//evil.example/faq/?a=1', 'http://localhost/evil.example/faq?a=1'],
            'triple slash host' => ['///evil.example/', 'http://localhost/evil.example'],
            'backslash host' => ['/\\evil.example/', 'http://localhost/evil.example'],
            'normal page' => ['/faq/', 'http://localhost/faq'],
        ];
    }

    #[DataProvider('trailingSlashPaths')]
    public function test_the_trailing_slash_redirect_stays_on_prism(string $path, string $expected): void
    {
        $response = $this->rawGet($path);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame($expected, $response->headers->get('Location'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function intendedUrls(): array
    {
        return [
            'another site' => ['https://evil.example/login', '/'],
            'protocol-relative' => ['//evil.example', '/'],
            'backslash' => ['/\\evil.example', '/'],
            'javascript' => ['javascript:alert(1)', '/'],
            'own page, full URL' => ['http://localhost/account?tab=1', '/account?tab=1'],
            'own page, path' => ['/account', '/account'],
        ];
    }

    #[DataProvider('intendedUrls')]
    public function test_sign_in_returns_only_to_a_page_on_prism(string $intended, string $expected): void
    {
        $this->judge();

        $this->withSession(['url.intended' => $intended])
            ->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('correct')])
            ->assertRedirect($expected);
    }

    public function test_a_guest_who_asked_for_a_page_gets_it_after_sign_in(): void
    {
        $this->judge();

        $this->get('/account')->assertRedirect('/login');
        $this->post('/login', ['email' => 'judge@example.test', 'password' => $this->fixturePassword('correct')])
            ->assertRedirect('/account');
    }

    public function test_a_failed_sign_in_never_redirects_to_a_foreign_referer(): void
    {
        $this->judge();

        // A same-origin Origin passes the CSRF origin check, so this reaches back().
        $response = $this->withHeaders(['Origin' => 'http://localhost', 'Referer' => 'https://evil.example/login'])
            ->post('/login', ['email' => 'judge@example.test', 'password' => 'wrong-password']);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertSame('localhost', parse_url($location, PHP_URL_HOST));
        $this->assertStringNotContainsString('evil.example', $location);
    }

    public function test_a_failed_sign_in_still_returns_to_the_prism_page_it_came_from(): void
    {
        $this->judge();

        $this->from('/login')
            ->post('/login', ['email' => 'judge@example.test', 'password' => 'wrong-password'])
            ->assertRedirect('/login');
    }

    /** @return array<string, array{0: mixed, 1: string}> */
    public static function allowlistCases(): array
    {
        return [
            'own path' => ['/faq', '/faq'],
            'own path with query and fragment' => ['/faq?a=1#top', '/faq?a=1#top'],
            'own origin' => ['http://localhost/faq', '/faq'],
            'own origin, root' => ['http://localhost', '/'],
            'APP_URL origin' => ['https://prismcua.duckdns.org/account', '/account'],
            'another host' => ['https://evil.example/', 'fallback'],
            'look-alike host' => ['http://localhost.evil.example/', 'fallback'],
            'user info trick' => ['http://localhost@evil.example/', 'fallback'],
            'other port' => ['http://localhost:8080/', 'fallback'],
            'protocol-relative' => ['//evil.example/x', 'fallback'],
            'backslash' => ['/\\evil.example', 'fallback'],
            'tab before host' => ["/\t/evil.example", 'fallback'],
            'newline' => ["/faq\r\nLocation: https://evil.example", 'fallback'],
            'javascript' => ['javascript:alert(1)', 'fallback'],
            'data' => ['data:text/html,x', 'fallback'],
            'relative without slash' => ['evil.example', 'fallback'],
            'empty' => ['', 'fallback'],
            'not a string' => [['/faq'], 'fallback'],
            'null' => [null, 'fallback'],
        ];
    }

    #[DataProvider('allowlistCases')]
    public function test_the_allowlist_keeps_only_prism_paths(mixed $url, string $expected): void
    {
        config(['app.url' => 'https://prismcua.duckdns.org']);

        $this->assertSame($expected, SafeRedirect::path($url, 'fallback', Request::create('http://localhost/')));
    }
}

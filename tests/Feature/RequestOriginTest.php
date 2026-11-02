<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyRequestOrigin;
use App\Models\AiFeedback;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CSRF defenses: the CSRF token check in the web group, plus the Origin,
 * Referer and Sec-Fetch-Site check in VerifyRequestOrigin.
 */
class RequestOriginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['prism.require_login' => false, 'app.url' => 'http://localhost']);
    }

    /** @return array<string, mixed> */
    private function rating(): array
    {
        return ['step' => 'diagnosis', 'rating' => 'helpful', 'source' => 'ai', 'scenario' => 'worn_tool', 'tick' => 0];
    }

    public function test_the_web_group_runs_the_csrf_token_check_and_the_origin_check(): void
    {
        $web = app(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(PreventRequestForgery::class, $web);
        $this->assertContains(VerifyRequestOrigin::class, $web);
    }

    public function test_every_write_route_in_prism_is_in_the_web_group(): void
    {
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            $writes = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);
            if ($writes === [] || ! str_starts_with($route->getActionName(), 'App\\')) {
                continue;
            }
            $checked++;
            $this->assertContains('web', $route->gatherMiddleware(), $route->uri().' must run the web group (CSRF checks)');
        }

        $this->assertGreaterThanOrEqual(9, $checked, 'Expected at least the 9 write routes in routes/web.php.');
    }

    public function test_a_same_origin_request_is_saved(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost', 'Sec-Fetch-Site' => 'same-origin'])
            ->postJson('/api/ai-feedback', $this->rating())
            ->assertCreated();

        $this->assertSame(1, AiFeedback::count());
    }

    public function test_a_request_with_no_origin_headers_passes_to_the_token_check(): void
    {
        $this->postJson('/api/ai-feedback', $this->rating())->assertCreated();
    }

    public function test_a_relative_referer_passes_to_the_token_check(): void
    {
        $this->withHeaders(['Referer' => '/dashboard'])
            ->postJson('/api/ai-feedback', $this->rating())
            ->assertCreated();
    }

    public function test_a_same_origin_referer_without_origin_is_saved(): void
    {
        $this->withHeaders(['Referer' => 'http://localhost/'])
            ->postJson('/api/ai-feedback', $this->rating())
            ->assertCreated();
    }

    public function test_the_app_url_origin_is_accepted_behind_the_proxy(): void
    {
        config(['app.url' => 'https://prismcua.duckdns.org']);

        $this->withHeaders(['Origin' => 'https://prismcua.duckdns.org'])
            ->postJson('/api/ai-feedback', $this->rating())
            ->assertCreated();
    }

    /** @return array<string, array{0: array<string, string>}> */
    public static function crossSiteHeaders(): array
    {
        return [
            'another origin' => [['Origin' => 'https://evil.example']],
            'null origin' => [['Origin' => 'null']],
            'same host, other scheme' => [['Origin' => 'https://localhost']],
            'same host, other port' => [['Origin' => 'http://localhost:8080']],
            'look-alike host' => [['Origin' => 'http://localhost.evil.example']],
            'not a URL' => [['Origin' => 'localhost']],
            'cross-site fetch' => [['Sec-Fetch-Site' => 'cross-site']],
            'same-site fetch' => [['Sec-Fetch-Site' => 'same-site']],
            'cross-site fetch with own origin' => [['Origin' => 'http://localhost', 'Sec-Fetch-Site' => 'cross-site']],
            'referer from another site' => [['Referer' => 'https://evil.example/page']],
            'referer from another port' => [['Referer' => 'http://localhost:9999/page']],
        ];
    }

    /** @param  array<string, string>  $headers */
    #[DataProvider('crossSiteHeaders')]
    public function test_a_cross_site_request_is_refused_and_nothing_is_saved(array $headers): void
    {
        Log::spy();

        $this->withHeaders($headers)
            ->postJson('/api/ai-feedback', $this->rating())
            ->assertForbidden()
            ->assertJson(['message' => VerifyRequestOrigin::MESSAGE]);

        $this->assertSame(0, AiFeedback::count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'Refused a cross-site request')->once();
    }

    public function test_a_cross_site_form_post_to_sign_in_is_refused(): void
    {
        config(['prism.require_login' => true]);

        $this->withHeaders(['Origin' => 'https://evil.example'])
            ->post('/login', ['email' => 'judge@example.test', 'password' => 'anything'])
            ->assertForbidden();
    }

    public function test_reads_are_not_checked(): void
    {
        $this->withHeaders(['Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site'])
            ->get('/')
            ->assertOk();
    }

    public function test_origin_of_normalizes_scheme_host_and_default_port(): void
    {
        $this->assertSame('https://prismcua.duckdns.org', VerifyRequestOrigin::originOf('HTTPS://PrismCUA.duckdns.org:443/path?q=1'));
        $this->assertSame('http://localhost:8000', VerifyRequestOrigin::originOf('http://localhost:8000/'));
        $this->assertNull(VerifyRequestOrigin::originOf('null'));
        $this->assertNull(VerifyRequestOrigin::originOf('javascript:alert(1)'));
        $this->assertNull(VerifyRequestOrigin::originOf(''));
    }
}

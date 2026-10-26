<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test reaches the real Have I Been Pwned API. By default it reports
     * no breach. A test that needs a breach hit fakes its own response.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    }

    /**
     * Act as a signed-in user. The user is not saved, so no database is
     * needed. Used by tests of the pages and routes behind the sign-in.
     */
    /**
     * A throwaway password for a test fixture. The base comes from phpunit.xml
     * (TEST_PASSWORD_BASE), so no password literal lives in the test files.
     * Always at least 12 characters, the app minimum.
     */
    protected function fixturePassword(string $label): string
    {
        return (string) env('TEST_PASSWORD_BASE', 'phpunit-fixture').'-'.$label;
    }

    protected function signIn(): static
    {
        return $this->actingAs(new User(['name' => 'Test Judge', 'email' => 'judge@example.test']));
    }
}

<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Act as a signed-in user. The user is not saved, so no database is
     * needed. Used by tests of the pages and routes behind the sign-in.
     */
    protected function signIn(): static
    {
        return $this->actingAs(new User(['name' => 'Test Judge', 'email' => 'judge@example.test']));
    }
}

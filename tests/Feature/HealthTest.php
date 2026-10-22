<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_ok_when_the_database_and_tables_are_there(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_health_is_public_even_when_the_sign_in_is_on(): void
    {
        config(['prism.require_login' => true]);

        $this->getJson('/health')->assertOk();
    }

    public function test_health_says_unavailable_when_a_table_is_missing_and_names_nothing(): void
    {
        Schema::drop('ai_feedback');

        $response = $this->getJson('/health');

        $response->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        $this->assertStringNotContainsString('ai_feedback', (string) $response->getContent());
    }

    public function test_health_says_unavailable_when_the_database_cannot_be_reached(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new \PDOException('SQLSTATE[HY000] unable to open database file'));

        $response = $this->getJson('/health');

        $response->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getContent());
    }
}

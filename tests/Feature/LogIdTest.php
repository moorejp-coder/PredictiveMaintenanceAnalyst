<?php

namespace Tests\Feature;

use App\Support\LogId;
use Tests\TestCase;

class LogIdTest extends TestCase
{
    public function test_an_email_in_a_log_line_is_a_keyed_hash_that_a_plain_hash_cannot_match(): void
    {
        $id = LogId::email('Judge@Example.test ');

        $this->assertSame($id, LogId::email('judge@example.test'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $id);
        $this->assertStringNotContainsString($id, hash('sha256', 'judge@example.test'));

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame($id, LogId::email('judge@example.test'));
    }
}

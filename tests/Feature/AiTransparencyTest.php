<?php

namespace Tests\Feature;

use App\Models\AiFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiTransparencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signIn();
    }

    public function test_ai_page_loads_and_states_the_key_facts(): void
    {
        $this->get('/ai')
            ->assertOk()
            ->assertSee('How PRISM uses AI')
            ->assertSee('Fixed rules calculate the risk score')
            ->assertSee('NIST AI Risk Management Framework')
            ->assertSee('WCAG 2.2')
            ->assertSee('291')
            ->assertSee('645');
    }

    public function test_dashboard_shows_the_high_band_accuracy_and_accessible_extras(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('How often a High score is right')
            ->assertSee('View the chart data as a table')
            ->assertSee('AI-GENERATED');

        $this->assertStringContainsString('role="meter"', file_get_contents(public_path('js/dashboard.js')));
    }

    public function test_a_rating_is_stored_without_personal_data(): void
    {
        $this->postJson('/api/ai-feedback', [
            'step' => 'diagnosis',
            'rating' => 'wrong',
            'source' => 'ai',
            'scenario' => 'overload',
            'tick' => 12,
        ])->assertCreated();

        $row = AiFeedback::sole();
        $this->assertSame('wrong', $row->rating);
        $this->assertSame('diagnosis', $row->step);
        $this->assertSame(12, $row->tick);
    }

    public function test_a_rating_with_free_text_or_a_bad_value_is_rejected(): void
    {
        $this->postJson('/api/ai-feedback', [
            'step' => 'diagnosis',
            'rating' => 'terrible machine, call me at 555-0100',
            'source' => 'ai',
            'scenario' => 'overload',
            'tick' => 1,
        ])->assertUnprocessable();

        $this->assertSame(0, AiFeedback::count());
    }
}

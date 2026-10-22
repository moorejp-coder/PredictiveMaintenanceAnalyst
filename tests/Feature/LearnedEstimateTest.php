<?php

namespace Tests\Feature;

use App\Services\Ml\FailureModel;
use App\Services\Ml\LearnedEstimate;
use Tests\TestCase;

class LearnedEstimateTest extends TestCase
{
    public function test_php_matches_python_on_the_parity_fixture(): void
    {
        $rows = json_decode((string) file_get_contents(base_path('tests/fixtures/model_parity.json')), true);
        $this->assertCount(300, $rows);
        $est = new LearnedEstimate;
        $model = new FailureModel;
        foreach ($rows as $r) {
            $reading = ['air_temp_k' => $r['air'], 'process_temp_k' => $r['proc'], 'rotational_speed_rpm' => $r['rpm'],
                'torque_nm' => $r['torque'], 'tool_wear_min' => $r['wear']];
            $p = $model->predict($est->vector($reading, $r['type']));
            $this->assertEqualsWithDelta($r['expected'], $p, 1e-6);
        }
    }

    public function test_model_card_lists_the_models_and_caveat_inputs(): void
    {
        $card = json_decode((string) file_get_contents(resource_path('ml/model_card.json')), true);
        $this->assertSame(9982, $card['dataset']['records_used']);
        $this->assertArrayHasKey('Logistic regression, raw inputs', $card['locked_test']['results']);
        $this->assertCount(5, $card['calibration']);
        $this->assertSame($card['inputs'], (new FailureModel)->features);
    }

    public function test_a_safe_reading_gets_a_low_estimate_and_a_failing_one_a_high_estimate(): void
    {
        $est = new LearnedEstimate;
        $safe = $est->estimate(['air_temp_k' => 298.1, 'process_temp_k' => 308.6, 'rotational_speed_rpm' => 1551, 'torque_nm' => 42.8, 'tool_wear_min' => 0]);
        $this->assertLessThan(0.01, $safe['probability']);
        $bad = $est->estimate(['air_temp_k' => 298.1, 'process_temp_k' => 308.6, 'rotational_speed_rpm' => 1300, 'torque_nm' => 65.0, 'tool_wear_min' => 230]);
        $this->assertGreaterThan(0.5, $bad['probability']);
        $this->assertNotNull($bad['range']);
    }

    public function test_formatting(): void
    {
        $this->assertSame('under 0.1%', LearnedEstimate::format(0.0004));
        $this->assertSame('0.5%', LearnedEstimate::format(0.005));
        $this->assertSame('98%', LearnedEstimate::format(0.983));
    }

    public function test_readings_api_includes_the_estimate_and_leaves_the_rule_score_alone(): void
    {
        $this->signIn();
        $res = $this->getJson('/api/readings?scenario=worn_tool&tick=3');
        $res->assertOk()->assertJsonStructure(['risk' => ['score', 'band'], 'estimate' => ['probability', 'percent', 'range']]);
    }
}

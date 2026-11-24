<?php

namespace Tests\Unit\Support;

use App\Support\VariantRules;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class VariantRulesTest extends TestCase
{
    public function test_background_color_rejects_non_color()
    {
        $validator = Validator::make(
            ['variants' => [['layer' => 'a', 'background_color' => 'expression(alert(1))']]],
            VariantRules::rules()
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('variants.0.background_color', $validator->errors()->messages());
    }

    public function test_background_color_accepts_valid_color()
    {
        $validator = Validator::make(
            ['variants' => [['layer' => 'a', 'background_color' => '#ff0000']]],
            VariantRules::rules()
        );

        $this->assertFalse($validator->fails());
    }
}

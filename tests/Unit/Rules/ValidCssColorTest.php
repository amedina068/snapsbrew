<?php

namespace Tests\Unit\Rules;

use App\Rules\ValidCssColor;
use Tests\TestCase;

class ValidCssColorTest extends TestCase
{
    public function test_accepts_hex_rgb_and_named_colors()
    {
        foreach (['#fff', '#ffff', '#ff0000', '#ff0000ff', 'rgb(255,0,0)', 'rgba(0,0,0,0.5)', 'hsl(120,50%,50%)', 'red', 'transparent'] as $color) {
            $this->assertTrue(ValidCssColor::matches($color), "expected {$color} to be valid");
        }
    }

    public function test_rejects_css_injection()
    {
        foreach ([
            'expression(alert(1))',
            'red;background:url(//evil)',
            'red}body{background:red',
            '#fff;color:red',
            "red'\"",
            123,
            null,
        ] as $color) {
            $this->assertFalse(ValidCssColor::matches($color), 'expected injection payload to be rejected');
        }
    }
}

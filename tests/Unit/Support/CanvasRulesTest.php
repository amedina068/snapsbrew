<?php

namespace Tests\Unit\Support;

use App\Support\CanvasRules;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class CanvasRulesTest extends TestCase
{
    private function validate(array $input): \Illuminate\Validation\Validator
    {
        return Validator::make($input, CanvasRules::rules(), CanvasRules::messages());
    }

    public function test_a_preset_resolves_to_its_dimensions()
    {
        $canvas = CanvasRules::resolve(['size' => 'instagram_story']);

        $this->assertSame(['width' => 1080, 'height' => 1920, 'size' => 'instagram_story'], $canvas);
    }

    public function test_explicit_dimensions_resolve_to_the_preset_they_match_or_custom()
    {
        $this->assertSame(
            ['width' => 1280, 'height' => 720, 'size' => 'youtube_thumbnail'],
            CanvasRules::resolve(['width' => 1280, 'height' => 720])
        );

        $this->assertSame(
            ['width' => 333, 'height' => 222, 'size' => 'custom'],
            CanvasRules::resolve(['width' => 333, 'height' => 222])
        );
    }

    public function test_a_preset_wins_over_dimensions_that_disagree_with_it()
    {
        $canvas = CanvasRules::resolve(['size' => 'instagram_story', 'width' => 10, 'height' => 10]);

        $this->assertSame(['width' => 1080, 'height' => 1920, 'size' => 'instagram_story'], $canvas);
    }

    public function test_a_canvas_needs_a_preset_or_both_dimensions()
    {
        $validator = $this->validate([]);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('Pass a `size` preset or an explicit `width` and `height`.', $validator->errors()->first('width'));
    }

    public function test_an_unknown_preset_names_the_tool_that_lists_presets()
    {
        $validator = $this->validate(['size' => 'myspace_banner']);

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('list-template-sizes', $validator->errors()->first('size'));
    }

    public function test_dimensions_are_bounded()
    {
        $this->assertTrue($this->validate(['width' => 0, 'height' => 10])->fails());
        $this->assertTrue($this->validate(['width' => 10, 'height' => 4001])->fails());
        $this->assertFalse($this->validate(['width' => 10, 'height' => 4000])->fails());
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Tag;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_belongs_to_company()
    {
        $tag = Tag::factory()->create();

        $this->assertInstanceOf(Company::class, $tag->company);
    }

    public function test_tag_has_templates_via_morph()
    {
        $user = User::factory()->create();
        $tag = Tag::factory()->create(['company_id' => $user->company_id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $tag->templates()->attach($template);

        $this->assertCount(1, $tag->fresh()->templates);
        $this->assertEquals($template->id, $tag->templates->first()->id);
    }

    public function test_tag_factory_generates_slug()
    {
        $tag = Tag::factory()->create();

        $this->assertNotNull($tag->slug);
        $this->assertNotEmpty($tag->slug);
    }
}

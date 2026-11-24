<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\Form;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use App\Services\TemplateVersioning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class TemplateDeleteTest extends TestCase
{
    use RefreshDatabase;

    // A publish renders a preview. The fake keeps Chrome out of the test.
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);
    }

    public function test_a_member_deletes_a_template_of_the_company(): void
    {
        $user = User::factory()->create();
        $template = $this->template($user);

        $this->actingAs($user)
            ->delete("/templates/{$template->uuid}")
            ->assertRedirect('/templates')
            ->assertSessionHas('status', "The template \"{$template->title}\" is deleted.");

        $this->assertModelMissing($template);

        $this->actingAs($user)->followingRedirects()
            ->delete('/templates/'.$this->template($user)->uuid)
            ->assertSee('is deleted.');
    }

    public function test_a_template_with_rendered_snaps_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $template = $this->template($user);
        $version = app(TemplateVersioning::class)->publish($template);
        $snap = Snap::factory()->completed()->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'source_template_version_id' => $version->id,
        ]);

        $this->actingAs($user)->delete("/templates/{$template->uuid}")->assertRedirect('/templates');

        $this->assertModelMissing($template);
        $this->assertModelMissing($snap);
        $this->assertDatabaseMissing('template_versions', ['template_id' => $template->id]);
    }

    public function test_the_forms_and_the_collection_links_of_the_template_go_with_it(): void
    {
        $user = User::factory()->create();
        $template = $this->template($user);
        $other = $this->template($user);
        $collection = TemplateCollection::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);
        $collection->templates()->attach([$template->id, $other->id]);
        $form = Form::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id, 'formable_id' => $template->id, 'formable_type' => Template::class]);

        $this->actingAs($user)->delete("/templates/{$template->uuid}")->assertRedirect('/templates');

        $this->assertModelMissing($form);
        $this->assertSame([$other->id], $collection->templates()->pluck('templates.id')->all());
    }

    public function test_a_template_of_another_company_is_not_found(): void
    {
        $user = User::factory()->create();
        $template = $this->template(User::factory()->create());

        $this->actingAs($user)->delete("/templates/{$template->uuid}")->assertNotFound();

        $this->assertModelExists($template);
    }

    public function test_the_template_page_asks_for_a_confirmation_before_the_delete(): void
    {
        $user = User::factory()->create();
        $template = $this->template($user);

        $this->actingAs($user)
            ->get("/templates/{$template->uuid}")
            ->assertOk()
            ->assertSee('action="/templates/'.$template->uuid.'"', false)
            ->assertSee('Delete this template?', false)
            ->assertSee('Delete template');
    }

    private function template(User $owner): Template
    {
        return Template::factory()->create(['user_id' => $owner->id, 'company_id' => $owner->company_id]);
    }
}

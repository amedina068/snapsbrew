<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateCollectionRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_removes_a_template_from_a_collection(): void
    {
        $user = User::factory()->create();
        [$collection, $kept, $removed] = $this->collectionWithTwoTemplates($user);

        $this->actingAs($user)
            ->delete("/collections/{$collection->uuid}/templates/{$removed->uuid}")
            ->assertRedirect("/collections/{$collection->uuid}")
            ->assertSessionHas('success', 'The template is removed from the collection.');

        $this->assertSame([$kept->id], $collection->templates()->pluck('templates.id')->all());
        $this->assertModelExists($removed);
    }

    public function test_a_member_deletes_a_collection_and_its_forms_but_not_its_templates(): void
    {
        $user = User::factory()->create();
        [$collection, $first, $second] = $this->collectionWithTwoTemplates($user);
        $form = Form::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'formable_id' => $collection->id,
            'formable_type' => TemplateCollection::class,
        ]);

        $this->actingAs($user)
            ->delete("/collections/{$collection->uuid}")
            ->assertRedirect('/collections')
            ->assertSessionHas('status', "The collection \"{$collection->name}\" is deleted.");

        $this->assertModelMissing($collection);
        $this->assertModelMissing($form);
        $this->assertDatabaseMissing('template_template_collection', ['template_collection_id' => $collection->id]);
        $this->assertModelExists($first);
        $this->assertModelExists($second);

        $this->get('/collections')->assertSee('is deleted.');
    }

    public function test_a_collection_of_another_company_is_not_found(): void
    {
        $user = User::factory()->create();
        [$collection, , $template] = $this->collectionWithTwoTemplates(User::factory()->create());

        $this->actingAs($user)->delete("/collections/{$collection->uuid}/templates/{$template->uuid}")->assertNotFound();
        $this->actingAs($user)->delete("/collections/{$collection->uuid}")->assertNotFound();

        $this->assertModelExists($collection);
        $this->assertSame(2, $collection->templates()->count());
    }

    public function test_the_collection_page_shows_both_actions_with_a_confirmation(): void
    {
        $user = User::factory()->create();
        [$collection, $template] = $this->collectionWithTwoTemplates($user);

        $this->actingAs($user)
            ->get("/collections/{$collection->uuid}")
            ->assertOk()
            ->assertSee('action="/collections/'.$collection->uuid.'/templates/'.$template->uuid.'"', false)
            ->assertSee('Remove from collection')
            ->assertSee('Remove this template from the collection?', false)
            ->assertSee('Delete collection')
            ->assertSee('Delete this collection?', false);
    }

    private function collectionWithTwoTemplates(User $user): array
    {
        $collection = TemplateCollection::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);
        $templates = Template::factory()->count(2)->create(['user_id' => $user->id, 'company_id' => $user->company_id]);
        $collection->templates()->attach($templates->pluck('id'));

        return [$collection, ...$templates->all()];
    }
}

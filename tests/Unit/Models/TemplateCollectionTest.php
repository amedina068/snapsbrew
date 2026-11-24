<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateCollectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_belongs_to_company()
    {
        $collection = TemplateCollection::factory()->create();

        $this->assertInstanceOf(Company::class, $collection->company);
    }

    public function test_collection_has_templates()
    {
        $user = User::factory()->create();
        $collection = TemplateCollection::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $templates = Template::factory()->count(3)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $collection->templates()->attach($templates);

        $this->assertCount(3, $collection->fresh()->templates);
    }

    public function test_collection_has_forms()
    {
        $user = User::factory()->create();
        $collection = TemplateCollection::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        Form::factory()->count(2)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'formable_id' => $collection->id,
            'formable_type' => TemplateCollection::class,
        ]);

        $this->assertCount(2, $collection->forms);
    }

    public function test_collection_has_uuid()
    {
        $collection = TemplateCollection::factory()->create();

        $this->assertNotNull($collection->uuid);
    }
}

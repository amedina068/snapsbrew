<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Tag;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagApiTest extends TestCase
{
    use RefreshDatabase;

    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    public function test_can_list_tags()
    {
        [$user, $token] = $this->createApiUser();

        Tag::factory()->count(3)->create([
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/tags');

        $response->assertStatus(200);
    }

    public function test_can_create_tag()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/tags', [
                'name' => 'New Tag',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('tags', [
            'company_id' => $user->company_id,
            'name' => 'New Tag',
        ]);
    }

    public function test_can_attach_tags_to_template()
    {
        [$user, $token] = $this->createApiUser();

        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $tag = Tag::factory()->create([
            'company_id' => $user->company_id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/templates/{$template->uuid}/tags", [
                'tag_ids' => [$tag->id],
            ]);

        $response->assertStatus(200);
        $this->assertCount(1, $template->fresh()->tags);
    }
}

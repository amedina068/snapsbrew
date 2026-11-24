<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * `GET /snaps/{snapId}` showed the HTML of any snap by its numeric id, with no
 * authentication. A guest could count up from 1 and read the snaps of every
 * company. The renderers stopped using the route when they started to render
 * the document HTML directly, so the route is gone.
 */
class PublicSnapPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Template}
     */
    private function owner(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'layers' => json_encode([[
                'key' => 'headline',
                'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'Private headline']],
            ]]),
        ]);

        return [$user, $template];
    }

    public function test_a_guest_cannot_read_a_snap_by_its_numeric_id()
    {
        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);
        [$user, $template] = $this->owner();
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', ['template_id' => $template->uuid])
            ->assertCreated();
        $this->app['auth']->forgetGuards();

        $snap = Snap::sole();

        $response = $this->withHeaders(['Authorization' => ''])->get("/snaps/{$snap->id}");

        $response->assertNotFound();
        $this->assertStringNotContainsString('Private headline', $response->getContent());
    }

    public function test_a_user_of_another_company_cannot_read_a_snap_by_its_numeric_id()
    {
        [, $template] = $this->owner();
        $snap = $template->snaps()->create([
            'user_id' => $template->user_id,
            'company_id' => $template->company_id,
            'type' => 'image',
            'variants' => json_encode([]),
        ]);
        [$stranger] = $this->owner();

        $this->actingAs($stranger)->get("/snaps/{$snap->id}")->assertNotFound();
    }

    public function test_the_render_path_renders_the_document_html_and_not_the_snap_page()
    {
        Storage::fake('public');
        $renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $renderer);
        [$user, $template] = $this->owner();
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/snaps', ['template_id' => $template->uuid])
            ->assertCreated()
            ->assertJsonPath('status', 'completed');

        $this->assertCount(1, $renderer->renderedDocuments);
        $document = $renderer->renderedDocuments[0];
        $this->assertStringStartsWith('<!DOCTYPE html>', $document);
        $this->assertStringContainsString('Private headline', $document);
        $this->assertStringNotContainsString('/snaps/', $document);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Contracts\SnapRenderer;
use App\Jobs\ProcessSnap;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\SnapService;
use App\Services\SourceVersionUnavailableException;
use App\Services\TemplateVersioning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class SnapServiceExactVersionTest extends TestCase
{
    use RefreshDatabase;

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
    }

    public function test_create_from_version_renders_the_requested_immutable_source_not_the_current_published_version(): void
    {
        [$user, $template] = $this->ownedTemplate();
        $exact = $this->version($template, 1, 'EXACT', 640, 360);
        $current = $this->version($template, 2, 'CURRENT', 1080, 1080, published: true);
        $template->forceFill(['published_version_id' => $current->id])->save();

        $snap = app(SnapService::class)->createFromVersion(
            $template,
            $exact,
            [],
            Snap::TYPE_IMAGE,
            false,
            null,
            $user->id,
            $user->company_id,
        );

        $this->assertSame($exact->id, $snap->source_template_version_id);
        $this->assertStringContainsString('EXACT', $snap->content);
        $this->assertStringNotContainsString('CURRENT', $snap->content);
        $this->assertTrue($snap->hasMedia('image'));

        $this->assertSame(
            ['width' => 640, 'height' => 360],
            array_intersect_key($this->renderer->screenshotCalls[0], array_flip(['width', 'height'])),
        );
    }

    public function test_create_from_version_rejects_a_source_from_a_different_template_without_creating_a_snap(): void
    {
        [$user, $template] = $this->ownedTemplate();
        [, $otherTemplate] = $this->ownedTemplate($user->company_id);
        $foreignSource = $this->version($otherTemplate, 1, 'OTHER', 640, 360);

        try {
            app(SnapService::class)->createFromVersion(
                $template, $foreignSource, [], Snap::TYPE_IMAGE, true, null,
                $user->id, $user->company_id,
            );
            $this->fail('Expected the exact source lookup to fail.');
        } catch (SourceVersionUnavailableException) {
            $this->assertDatabaseCount('snaps', 0);
        }
    }

    public function test_queued_exact_version_snap_renders_the_selected_source_after_the_template_is_edited_and_published(): void
    {
        Bus::fake();
        [$user, $template] = $this->ownedTemplate();
        $exact = $this->version($template, 1, 'EXACT', 640, 360);

        $snap = app(SnapService::class)->createFromVersion(
            $template, $exact, [], Snap::TYPE_IMAGE, true, null,
            $user->id, $user->company_id,
        );

        $template->forceFill([
            'layers' => json_encode([[
                'key' => 'copy',
                'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'PUBLISHED AFTER DISPATCH']],
            ]]),
            'width' => 1200,
            'height' => 628,
        ])->save();
        app(TemplateVersioning::class)->publish($template->fresh());

        $this->assertNotSame($exact->id, $template->fresh()->published_version_id);

        (new ProcessSnap($snap))->handle();

        $snap->refresh();
        $this->assertSame($exact->id, $snap->source_template_version_id);
        $this->assertStringContainsString('EXACT', $snap->content);
        $this->assertStringNotContainsString('PUBLISHED AFTER DISPATCH', $snap->content);
        $this->assertTrue($snap->hasMedia('image'));
        $this->assertSame(
            ['width' => 640, 'height' => 360],
            array_intersect_key($this->renderer->screenshotCalls[0], array_flip(['width', 'height'])),
        );
    }

    public function test_create_from_version_rejects_a_template_from_another_company_without_creating_a_snap(): void
    {
        [$user] = $this->ownedTemplate();
        [, $otherTemplate] = $this->ownedTemplate();
        $source = $this->version($otherTemplate, 1, 'OTHER', 640, 360);

        try {
            app(SnapService::class)->createFromVersion(
                $otherTemplate, $source, [], Snap::TYPE_IMAGE, true, null,
                $user->id, $user->company_id,
            );
            $this->fail('Expected the company-scoped template lookup to fail.');
        } catch (SourceVersionUnavailableException) {
            $this->assertDatabaseCount('snaps', 0);
        }
    }

    public function test_create_from_version_rejects_a_source_deleted_after_package_preflight(): void
    {
        [$user, $template] = $this->ownedTemplate();
        $source = $this->version($template, 1, 'DELETED', 640, 360);
        $source->delete();

        $this->expectException(SourceVersionUnavailableException::class);

        try {
            app(SnapService::class)->createFromVersion(
                $template, $source, [], Snap::TYPE_IMAGE, true, null,
                $user->id, $user->company_id,
            );
        } finally {
            $this->assertDatabaseCount('snaps', 0);
        }
    }

    /** @return array{User, Template} */
    private function ownedTemplate(?int $companyId = null): array
    {
        $company = $companyId ? Company::findOrFail($companyId) : Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        return [$user, $template];
    }

    private function version(Template $template, int $number, string $text, int $width, int $height, bool $published = false): TemplateVersion
    {
        return TemplateVersion::factory()->create([
            'template_id' => $template->id,
            'version_number' => $number,
            'layers' => json_encode([[
                'key' => 'copy',
                'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => $text]],
            ]]),
            'groups' => json_encode([]),
            'width' => $width,
            'height' => $height,
            'published' => $published,
        ]);
    }
}

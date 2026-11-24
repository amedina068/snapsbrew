<?php

namespace Tests\Feature\Services;

use App\Contracts\SnapRenderer;
use App\Jobs\GenerateTemplateVersionPreview;
use App\Jobs\ProcessSnap;
use App\Models\Company;
use App\Models\IdempotencyKey;
use App\Models\Snap;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\SnapService;
use App\Support\InlineDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class SnapServiceDesignTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        $company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $company->id]);
    }

    private function design(string $text = 'Inline'): InlineDesign
    {
        return InlineDesign::fromInput(
            [['key' => 'headline', 'type' => 'text', 'properties' => [
                ['name' => 'textContent', 'value' => $text],
            ]]],
            320,
            200,
            $this->user->company_id,
        );
    }

    private function create(?string $key = null, ?string $format = null): Snap
    {
        return app(SnapService::class)->createFromDesign(
            $this->design(),
            Snap::TYPE_IMAGE,
            $this->user->id,
            $this->user->company_id,
            $format,
            false,
            $key,
        );
    }

    public function test_it_writes_a_template_less_source_version_and_a_snap_that_links_to_it()
    {
        Bus::fake();

        $snap = $this->create();

        $this->assertTrue($snap->wasRecentlyCreated);
        $this->assertNull($snap->template_id);

        $source = $snap->sourceTemplateVersion;
        $this->assertNotNull($source);
        $this->assertNull($source->template_id);
        $this->assertSame(1, $source->version_number);
        $this->assertFalse($source->published);
        $this->assertSame(320, $source->width);
        $this->assertSame(200, $source->height);
        $this->assertStringContainsString('Inline', $source->layers);
        $this->assertSame($this->user->id, $source->created_by);
        $this->assertSame('[]', $snap->variants);
        $this->assertSame('png', $snap->format);

        Bus::assertDispatchedSync(ProcessSnap::class, 1);
        Bus::assertNotDispatched(GenerateTemplateVersionPreview::class);
    }

    public function test_it_renders_the_snap_when_the_job_runs_inline()
    {
        $snap = $this->create();

        $this->assertSame(Snap::STATUS_COMPLETED, $snap->status);
        $this->assertNotEmpty($snap->imageUrl);
    }

    public function test_two_calls_with_no_key_each_get_their_own_source_version()
    {
        Bus::fake();

        $first = $this->create();
        $second = $this->create();

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->source_template_version_id, $second->source_template_version_id);
        $this->assertSame(2, TemplateVersion::query()->whereNull('template_id')->where('version_number', 1)->count());
    }

    public function test_two_calls_with_different_keys_each_get_their_own_source_version()
    {
        Bus::fake();

        $first = $this->create('key-a');
        $second = $this->create('key-b');

        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->source_template_version_id, $second->source_template_version_id);
        $this->assertSame(2, TemplateVersion::query()->whereNull('template_id')->where('version_number', 1)->count());
        $this->assertSame(
            [IdempotencyKey::STATUS_COMPLETED, IdempotencyKey::STATUS_COMPLETED],
            IdempotencyKey::query()->whereIn('key', ['key-a', 'key-b'])->orderBy('key')->pluck('status')->all()
        );
    }

    public function test_a_repeated_key_returns_the_same_snap_and_no_second_version()
    {
        Bus::fake();

        $first = $this->create('retry-1');
        $second = $this->create('retry-1');

        $this->assertSame($first->id, $second->id);
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame(1, Snap::query()->count());
        $this->assertSame(1, TemplateVersion::query()->whereNull('template_id')->count());
    }

    public function test_a_render_failure_marks_the_snap_failed_and_releases_the_claim()
    {
        $this->app->instance(SnapRenderer::class, new class implements SnapRenderer
        {
            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                throw new \RuntimeException('chrome went away');
            }

            public function pdfBase64(string $html, int $width, int $height, $pages = null): string
            {
                throw new \RuntimeException('chrome went away');
            }
        });

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode($message->context);
        });

        try {
            $this->create('retry-2');
            $this->fail('The render exception did not surface.');
        } catch (\RuntimeException $e) {
            $this->assertSame('chrome went away', $e->getMessage());
        }

        $snap = Snap::query()->first();
        $this->assertSame(Snap::STATUS_FAILED, $snap->status);
        $this->assertSame(Snap::ERROR_RENDER_FAILED, $snap->last_error);
        // The row holds the code. The log holds the message of the renderer.
        $this->assertTrue(collect($logged)->contains(fn ($line) => str_contains($line, 'chrome went away')));
        // A released claim is marked failed so a later retry can take it.
        $this->assertSame(IdempotencyKey::STATUS_FAILED, IdempotencyKey::query()->where('key', 'retry-2')->value('status'));
    }
}

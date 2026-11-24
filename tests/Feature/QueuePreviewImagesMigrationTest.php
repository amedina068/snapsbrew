<?php

namespace Tests\Feature;

use App\Jobs\GenerateTemplateVersionPreview;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * The preview backfill migration queues one job for each version with no
 * preview. It must not render inside `php artisan migrate`.
 *
 * `.env.example` sets QUEUE_CONNECTION=sync. With sync, a dispatched job runs
 * at once, so the migration started Chrome for each starter template. On a
 * machine with Node outside /usr/local/bin, `php artisan migrate` then failed
 * half way, and a new contributor could not finish the setup in README.md.
 */
class QueuePreviewImagesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_a_preview_for_each_version_with_an_async_queue(): void
    {
        $this->versionWithoutPreview();

        $this->runMigration('redis');

        Bus::assertDispatchedTimes(GenerateTemplateVersionPreview::class, TemplateVersion::query()->count());
    }

    public function test_it_renders_nothing_with_the_sync_queue(): void
    {
        $this->versionWithoutPreview();

        $this->runMigration('sync');

        Bus::assertNotDispatched(GenerateTemplateVersionPreview::class);
    }

    private function versionWithoutPreview(): TemplateVersion
    {
        $template = Template::factory()->create();

        return TemplateVersion::query()->create([
            'template_id' => $template->id,
            'version_number' => 1,
            'layers' => '[]',
            'groups' => '[]',
            'width' => 100,
            'height' => 100,
            'published' => true,
        ]);
    }

    /**
     * The migration skips itself under the test suite. The environment is
     * set to `local` for the call, so the code path of a real `migrate` runs.
     */
    private function runMigration(string $queue): void
    {
        Bus::fake();
        config(['queue.default' => $queue]);
        $environment = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            $migration = require database_path('migrations/2026_06_23_000500_queue_preview_images_for_existing_template_versions.php');
            $migration->up();
        } finally {
            $this->app['env'] = $environment;
        }
    }
}

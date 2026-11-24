<?php

use App\Jobs\GenerateTemplateVersionPreview;
use App\Models\TemplateVersion;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Backfill preview images for versions that predate the stored-image feature
     * (e.g. the auto-published v1 from 000400). Rendering is QUEUED, never inline —
     * the migration must not launch Browsershot per row. In production the queue is
     * async (redis) so this returns immediately and workers render later.
     *
     * Skipped under the test suite and with the sync queue. With sync, dispatched
     * jobs run inline and launch a real headless browser for every seeded
     * template during `migrate`. `.env.example` sets sync, so a new local setup
     * failed here when Node was not at the default path. A version with no
     * preview image still renders snaps.
     *
     * Eloquent only (whereDoesntHave + chunkById) so the query is portable across
     * MySQL and SQLite. Only imageless versions are queued; the job is idempotent.
     */
    public function up(): void
    {
        if (app()->runningUnitTests() || config('queue.default') === 'sync') {
            return;
        }

        TemplateVersion::query()
            ->whereDoesntHave('media', fn ($query) => $query->where('collection_name', 'preview'))
            ->chunkById(200, function ($versions) {
                foreach ($versions as $version) {
                    GenerateTemplateVersionPreview::dispatch($version);
                }
            });
    }

    public function down(): void
    {
        // Queued image generation is not reversible.
    }
};

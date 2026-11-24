<?php

use App\Models\Template;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Auto-publish every existing template as version 1 so the published-version
     * render contract holds immediately. Per-row inserts via chunkById — portable
     * across MySQL and SQLite (no dialect-specific UPDATE ... JOIN).
     */
    public function up(): void
    {
        Template::query()
            ->whereNull('published_version_id')
            ->chunkById(200, function ($templates) {
                foreach ($templates as $template) {
                    $version = $template->versions()->create([
                        'version_number' => 1,
                        'layers' => $template->layers,
                        'groups' => $template->groups,
                        'width' => $template->width,
                        'height' => $template->height,
                        'published' => true,
                        'created_by' => $template->user_id,
                    ]);

                    $template->forceFill(['published_version_id' => $version->id])->save();
                }
            });
    }

    public function down(): void
    {
        // Non-reversible data backfill; the schema migration's down() drops the
        // versions table and FK, which is the meaningful rollback.
    }
};

<?php

namespace Tests\Feature\Database;

use App\Models\Snap;
use App\Models\TemplateVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A snap rendered from an inline design has a `template_versions` row as its
 * immutable source and no `templates` row above it. These tests pin the two
 * schema facts that design depends on.
 *
 * The composite unique index `(template_id, version_number)` treats every NULL
 * as distinct on MySQL and on SQLite, so many template-less versions share
 * `version_number` 1. The composite foreign key on `snaps` is MATCH SIMPLE and
 * skips a row whose `template_id` is NULL, so a single-column foreign key on
 * `source_template_version_id` is what the database enforces for those rows.
 */
class TemplateLessRenderSourceTest extends TestCase
{
    use RefreshDatabase;

    private function templateLessVersion(): TemplateVersion
    {
        return TemplateVersion::query()->create([
            'template_id' => null,
            'version_number' => 1,
            'layers' => json_encode([]),
            'groups' => null,
            'width' => 100,
            'height' => 100,
            'published' => false,
        ]);
    }

    public function test_two_template_less_versions_can_both_hold_version_number_one()
    {
        $first = $this->templateLessVersion();
        $second = $this->templateLessVersion();

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, TemplateVersion::query()->whereNull('template_id')->count());
    }

    public function test_a_snap_cannot_point_at_a_source_version_that_does_not_exist()
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/i');

        DB::table('snaps')->insert([
            'uuid' => 'dangling',
            'template_id' => null,
            'source_template_version_id' => 999999,
            'status' => Snap::STATUS_PROCESSING,
            'type' => Snap::TYPE_IMAGE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_source_version_that_a_snap_references_cannot_be_deleted()
    {
        $version = $this->templateLessVersion();

        DB::table('snaps')->insert([
            'uuid' => 'inline',
            'template_id' => null,
            'source_template_version_id' => $version->id,
            'status' => Snap::STATUS_PROCESSING,
            'type' => Snap::TYPE_IMAGE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/foreign key/i');

        DB::table('template_versions')->where('id', $version->id)->delete();
    }

    public function test_the_migration_is_idempotent_after_online_ddl()
    {
        // RefreshDatabase already applied it. A DBA can apply the DDL first;
        // Artisan then runs the migration only to record it.
        $migration = require database_path('migrations/2026_09_19_000000_allow_template_less_render_sources.php');
        $migration->up();

        $this->test_a_snap_cannot_point_at_a_source_version_that_does_not_exist();
    }

    public function test_down_refuses_once_an_inline_version_exists()
    {
        // The docblock on down() promises this failure. A rollback that drops
        // rendered snaps is a data decision, so the NOT NULL violation is the
        // outcome the migration wants.
        $this->templateLessVersion();

        $migration = require database_path('migrations/2026_09_19_000000_allow_template_less_render_sources.php');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/not null/i');

        $migration->down();
    }
}

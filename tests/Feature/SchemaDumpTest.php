<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Services\OnboardingSnapDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RefreshDatabase loads database/schema/<driver>-schema.sql, then runs the
 * migrations that the dump does not list. The data migrations must still
 * write their rows, as on an install that runs each migration.
 */
class SchemaDumpTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_onboarding_templates_exist_after_the_dump_loads()
    {
        $showcase = Template::query()->whereNull('user_id')->where('title', OnboardingSnapDispatcher::SHOWCASE_TITLE)->first();

        $this->assertNotNull($showcase);
        $this->assertNotNull($showcase->published_version_id);
        $this->assertSame(6, Template::query()->whereNull('user_id')->whereNotNull('published_version_id')->count());
    }

    public function test_every_migration_is_recorded()
    {
        $files = collect(glob(database_path('migrations/*.php')))->map(fn ($path) => basename($path, '.php'));

        $this->assertEqualsCanonicalizing($files->all(), DB::table('migrations')->pluck('migration')->all());
    }
}

<?php

namespace Tests\Unit;

use App\Support\Queues;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Docker image reads lists and defaults that the app also holds. These
 * tests fail when the two sides are different.
 */
class DockerRuntimeFilesTest extends TestCase
{
    public function test_the_queue_worker_of_the_image_reads_the_queues_in_the_order_of_horizon()
    {
        $run = file_get_contents(base_path('deploy/docker/s6-rc.d/snapsbrew-queue/run'));

        $this->assertStringContainsString('--queue='.implode(',', Queues::all()).' ', $run);
    }

    public static function imageDefaults(): array
    {
        return [
            ['DB_CONNECTION', 'sqlite'],
            ['DB_DATABASE', '/data/database.sqlite'],
            ['QUEUE_CONNECTION', 'database'],
            ['RENDER_DRIVER', 'browsershot'],
            ['BROWSERSHOT_INSTANCE', 'http://127.0.0.1:9222'],
            ['BROWSERSHOT_CHROME_PATH', '/usr/lib/chromium/chromium-headless-shell'],
            ['RENDER_ASSET_URL', 'http://127.0.0.1:8080'],
            ['APP_KEY_FILE', '/data/.secrets/app_key'],
            ['LARAVEL_STORAGE_PATH', '/data/storage'],
        ];
    }

    #[DataProvider('imageDefaults')]
    public function test_the_image_sets_the_default(string $name, string $value)
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^\s+'.preg_quote("{$name}={$value}", '/').'\s/m', $dockerfile);
    }

    public function test_the_image_has_the_clients_that_load_a_schema_dump()
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        $this->assertMatchesRegularExpression('/^\s+sqlite3\s/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^\s+mariadb-client-core\s/m', $dockerfile);
    }

    public function test_the_secrets_script_writes_the_key_file_that_the_image_reads()
    {
        $script = file_get_contents(base_path('deploy/docker/entrypoint.d/05-snapsbrew-secrets.sh'));

        $this->assertStringContainsString('${APP_KEY_FILE:-$data_dir/.secrets/app_key}', $script);
        $this->assertStringContainsString('umask 077', $script);
    }

    public static function schemaDumps(): array
    {
        return [['mysql-schema.sql'], ['sqlite-schema.sql']];
    }

    /**
     * A dump holds no rows except the migrations table. A migration that the
     * dump lists does not run, so a data migration must stay out of the list.
     */
    #[DataProvider('schemaDumps')]
    public function test_a_schema_dump_does_not_list_the_data_migrations(string $file)
    {
        $dump = file_get_contents(database_path("schema/{$file}"));

        $this->assertStringContainsString("'2026_11_16_000000_remove_billing'", $dump);

        foreach ([
            '2026_05_15_000200_seed_onboarding_templates',
            '2026_06_23_000400_backfill_template_versions',
            '2026_06_23_000500_queue_preview_images_for_existing_template_versions',
        ] as $migration) {
            $this->assertStringNotContainsString("'{$migration}'", $dump);
        }
    }
}

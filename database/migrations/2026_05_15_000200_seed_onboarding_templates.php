<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Seeds the 6 onboarding template rows so production deploys ship them
 * without depending on TemplateSeeder being run by hand. Idempotent: each
 * INSERT carries its own UUID, so re-running the migration is a no-op
 * (a duplicate-key error is swallowed).
 *
 * The raw SQL files were authored before `templates.size` existed and
 * MySQL strict mode rejects the missing column. We patch the column list
 * and VALUES tuple on the fly to inject `size`, derived from width x
 * height — same mapping as `add_size_column_to_templates_table` so the
 * downstream readableSize accessor stays consistent.
 */
return new class extends Migration
{
    private array $files = [
        'onboarding-showcase.txt',
        'onboarding-social-post.txt',
        'onboarding-social-story.txt',
        'onboarding-blog-og.txt',
        'onboarding-certificate.txt',
        'onboarding-marketing-banner.txt',
    ];

    public function up(): void
    {
        foreach ($this->files as $file) {
            if (! Storage::disk('local')->exists("templates/{$file}")) {
                continue;
            }

            $sql = $this->injectSizeColumn(Storage::disk('local')->get("templates/{$file}"));

            try {
                DB::insert($sql);
            } catch (QueryException $e) {
                if (! $this->isDuplicateError($e)) {
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('templates')
            ->whereNull('user_id')
            ->where('title', 'like', '[Onboarding]%')
            ->delete();
    }

    /**
     * Rewrites the canned INSERT statement to include `size` between
     * `layers` and `created_at`. Idempotent — if `size` is already in the
     * column list, the SQL is returned untouched.
     */
    private function injectSizeColumn(string $sql): string
    {
        if (str_contains($sql, '`size`')) {
            return $sql;
        }

        $sql = preg_replace(
            '/`layers`,\s*`created_at`/',
            '`layers`, `size`, `created_at`',
            $sql,
            1
        );

        if (! preg_match("/'(\d+)',\s*'(\d+)',\s*'\[/", $sql, $m)) {
            return $sql;
        }

        $sizeValue = $this->resolveSize((int) $m[1], (int) $m[2]);

        return preg_replace(
            "/(\]',)\s*('\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}',\s*'\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}'\s*\);?)/",
            "$1 '{$sizeValue}', $2",
            $sql,
            1
        );
    }

    /**
     * Mirrors the mapping in `add_size_column_to_templates_table`.
     */
    private function resolveSize(int $width, int $height): string
    {
        return match ("{$width}x{$height}") {
            '1080x1920' => 'instagram_story',
            '1080x1350' => 'instagram_post',
            '1200x670' => 'twitter_card',
            '1200x1200' => 'facebook_card',
            default => 'custom',
        };
    }

    private function isDuplicateError(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;
        $driverCode = $e->errorInfo[1] ?? null;

        // 23000 = MySQL/PG integrity violation, 1062 = MySQL duplicate,
        // 19 = SQLite constraint, 2067 = SQLite unique.
        return $sqlState === '23000'
            || in_array($driverCode, [1062, 19, 2067], true);
    }
};

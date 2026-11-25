<?php

namespace App\Rendering;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Gives the renderer the Google fonts of a snap as `@font-face` rules with
 * `data:` URIs, so the browser needs no network to draw the text.
 *
 * The library reads a family from two folders with the same layout: one
 * folder per family, with a `font.css` file and the woff2 files it names.
 *
 * - `resources/fonts` holds the families of the starter and onboarding
 *   templates. They ship with the app.
 * - `storage/app/fonts` is the cache. The first render that uses another
 *   Google font downloads it there one time.
 *
 * The files are what Google Fonts serves for `css2?family=<name>`: the regular
 * weight, in each unicode subset. The editor asks Google for the same files,
 * so the render looks like the editor.
 */
class FontLibrary
{
    public const FAMILY_PATTERN = '/^[A-Za-z0-9 _-]{1,100}$/';

    /** Google Fonts serves woff2 files only to a browser that it knows. */
    private const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

    private const CSS_URL = 'https://fonts.googleapis.com/css2';

    private const FILE_HOST = 'https://fonts.gstatic.com/';

    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    /** After a failed download, the library waits this long before it asks Google again. */
    private const RETRY_AFTER_SECONDS = 600;

    public static function slug(string $family): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($family)), '-');
    }

    /** The `@font-face` rules of one family with every file inlined, or null when the family is not available. */
    public function embeddedCss(string $family): ?string
    {
        if (! preg_match(self::FAMILY_PATTERN, $family) || self::slug($family) === '') {
            return null;
        }

        $slug = self::slug($family);

        foreach ([$this->bundledPath(), $this->cachePath()] as $root) {
            if (File::exists("{$root}/{$slug}/font.css")) {
                return $this->inline("{$root}/{$slug}");
            }
        }

        if (! config('services.render.font_downloads', true) || Cache::has($this->retryKey($slug))) {
            return null;
        }

        try {
            $this->download($family, $slug);
        } catch (Throwable $exception) {
            Cache::put($this->retryKey($slug), true, self::RETRY_AFTER_SECONDS);
            Log::info('The font download failed.', ['family' => $family, 'reason' => $exception->getMessage()]);

            return null;
        }

        return $this->inline("{$this->cachePath()}/{$slug}");
    }

    private function inline(string $directory): ?string
    {
        $missing = false;

        $css = preg_replace_callback('/url\(([a-z0-9-]+\.woff2)\)/', function ($match) use ($directory, &$missing) {
            $path = "{$directory}/{$match[1]}";

            if (! File::exists($path)) {
                $missing = true;

                return $match[0];
            }

            return 'url(data:font/woff2;base64,'.base64_encode(File::get($path)).')';
        }, File::get("{$directory}/font.css"));

        return $missing ? null : $css;
    }

    /**
     * Download the css and every woff2 file into a new folder, then move the
     * folder into place. A failed download leaves no partial family behind.
     */
    private function download(string $family, string $slug): void
    {
        $css = Http::withUserAgent(self::USER_AGENT)
            ->connectTimeout(3)
            ->timeout(10)
            ->get(self::CSS_URL, ['family' => $family])
            ->throw()
            ->body();

        if (! preg_match_all('~(?:/\*\s*([a-z0-9-]+)\s*\*/\s*)?@font-face\s*\{[^}]*\}~i', $css, $blocks, PREG_SET_ORDER)) {
            throw new RuntimeException('The response has no @font-face rule.');
        }

        $temporary = $this->cachePath().'/.download-'.$slug.'-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($temporary);

        try {
            $rules = [];

            foreach ($blocks as $index => $block) {
                $name = ($block[1] ?? '') !== '' ? strtolower($block[1]) : 'part';
                $file = in_array("{$name}.woff2", array_keys($rules), true) ? "{$name}-{$index}.woff2" : "{$name}.woff2";

                if (! preg_match('~src:\s*url\((https://[^)\s]+)\)~', $block[0], $source) || ! str_starts_with($source[1], self::FILE_HOST)) {
                    throw new RuntimeException('A font file is not on fonts.gstatic.com.');
                }

                $bytes = Http::connectTimeout(3)->timeout(10)->get($source[1])->throw()->body();

                if ($bytes === '' || strlen($bytes) > self::MAX_FILE_BYTES) {
                    throw new RuntimeException('A font file is empty or too large.');
                }

                File::put("{$temporary}/{$file}", $bytes);
                $rules[$file] = str_replace($source[0], "src: url({$file})", $block[0]);
            }

            File::put("{$temporary}/font.css", implode("\n", $rules)."\n");

            if (! File::exists("{$this->cachePath()}/{$slug}/font.css")) {
                File::deleteDirectory("{$this->cachePath()}/{$slug}");
                File::moveDirectory($temporary, "{$this->cachePath()}/{$slug}");
            }
        } finally {
            File::deleteDirectory($temporary);
        }
    }

    private function retryKey(string $slug): string
    {
        return "render-fonts:unavailable:{$slug}";
    }

    private function bundledPath(): string
    {
        return resource_path('fonts');
    }

    private function cachePath(): string
    {
        return (string) config('services.render.font_cache_path', storage_path('app/fonts'));
    }
}

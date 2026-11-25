<?php

namespace Tests\Unit\Rendering;

use App\Rendering\FontLibrary;
use App\Support\StarterTemplates;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The renderer must not need the network for a font. The library reads the
 * bundled starter fonts, then its own cache, and downloads any other Google
 * font one time into that cache.
 */
class FontLibraryTest extends TestCase
{
    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = sys_get_temp_dir().'/font-library-test-'.uniqid();
        config()->set('services.render.font_cache_path', $this->cache);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cache);

        parent::tearDown();
    }

    public static function starterFamilies(): array
    {
        return [['Inter'], ['DM Serif Display'], ['Playfair Display'], ['Anton'], ['Poppins'], ['Pacifico'], ['Righteous']];
    }

    /**
     * The templates that every install gets: the starter templates and the
     * onboarding templates of the `seed_onboarding_templates` migration.
     *
     * @return list<string>
     */
    private static function shippedTemplateFamilies(): array
    {
        $families = [];

        foreach (StarterTemplates::all() as $template) {
            foreach ($template['layers'] as $layer) {
                foreach ($layer['properties'] as $property) {
                    if ($property['name'] === 'fontFamily') {
                        $families[] = $property['value'];
                    }
                }
            }
        }

        foreach (File::glob(storage_path('app/templates/onboarding-*.txt')) as $file) {
            preg_match_all('/"name":"fontFamily","value":"([^"]+)"/', File::get($file), $matches);
            array_push($families, ...$matches[1]);
        }

        return array_values(array_unique($families));
    }

    public function test_every_font_of_a_shipped_template_is_bundled()
    {
        $families = self::shippedTemplateFamilies();
        $this->assertContains('Righteous', $families);

        foreach ($families as $family) {
            $this->assertFileExists(resource_path('fonts/'.FontLibrary::slug($family).'/font.css'), "The shipped templates use {$family}, which is not in resources/fonts.");
            $this->assertContains([$family], self::starterFamilies(), "Add {$family} to starterFamilies().");
        }
    }

    #[DataProvider('starterFamilies')]
    public function test_a_bundled_starter_family_embeds_as_data_uris_with_no_request(string $family)
    {
        Http::fake();

        $css = app(FontLibrary::class)->embeddedCss($family);

        $this->assertNotNull($css);
        $this->assertStringContainsString("font-family: '{$family}';", $css);
        $this->assertStringContainsString('font-weight: 400;', $css);
        $this->assertStringContainsString('src: url(data:font/woff2;base64,', $css);
        $this->assertStringNotContainsString('gstatic', $css);
        Http::assertNothingSent();
    }

    public function test_every_bundled_family_ships_its_license()
    {
        foreach (self::starterFamilies() as [$family]) {
            $directory = resource_path('fonts/'.FontLibrary::slug($family));

            $this->assertFileExists("{$directory}/OFL.txt", "{$family} has no license file.");
            $this->assertStringContainsString('SIL OPEN FONT LICENSE', strtoupper(File::get("{$directory}/OFL.txt")));
        }
    }

    public function test_another_google_family_downloads_one_time_into_the_cache()
    {
        Http::fake([
            'fonts.googleapis.com/*' => Http::response($this->googleCss('Lobster', ['latin-ext' => 'aaa', 'latin' => 'bbb'])),
            'fonts.gstatic.com/s/lobster/aaa.woff2' => Http::response('LATIN-EXT-BYTES'),
            'fonts.gstatic.com/s/lobster/bbb.woff2' => Http::response('LATIN-BYTES'),
        ]);

        $css = app(FontLibrary::class)->embeddedCss('Lobster');

        $this->assertStringContainsString('data:font/woff2;base64,'.base64_encode('LATIN-BYTES'), $css);
        $this->assertStringContainsString('data:font/woff2;base64,'.base64_encode('LATIN-EXT-BYTES'), $css);
        $this->assertStringContainsString('unicode-range: U+0000-00FF', $css);
        $this->assertFileExists("{$this->cache}/lobster/font.css");
        $this->assertFileExists("{$this->cache}/lobster/latin.woff2");

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://fonts.googleapis.com/css2?family=Lobster')
            && str_contains($request->header('User-Agent')[0] ?? '', 'Chrome'));
        Http::assertSentCount(3);

        // The second render reads the cache.
        $this->assertSame($css, app(FontLibrary::class)->embeddedCss('Lobster'));
        Http::assertSentCount(3);
    }

    public function test_a_family_that_cannot_download_returns_null_and_is_not_asked_again_soon()
    {
        Http::fake(['*' => Http::response('Bad Request', 400)]);

        $this->assertNull(app(FontLibrary::class)->embeddedCss('Not A Real Font'));
        $this->assertNull(app(FontLibrary::class)->embeddedCss('Not A Real Font'));

        Http::assertSentCount(1);
        $this->assertDirectoryDoesNotExist("{$this->cache}/not-a-real-font");
    }

    public function test_a_font_file_from_another_host_is_refused()
    {
        Http::fake([
            'fonts.googleapis.com/*' => Http::response(
                "/* latin */\n@font-face {\n  font-family: 'Lobster';\n  font-style: normal;\n  font-weight: 400;\n"
                ."  src: url(https://fonts.gstatic.com.evil.example/latest.woff2) format('woff2');\n  unicode-range: U+0000-00FF;\n}\n"
            ),
        ]);

        $this->assertNull(app(FontLibrary::class)->embeddedCss('Lobster'));
        Http::assertSentCount(1);
    }

    public function test_a_family_name_outside_the_allowlist_makes_no_request()
    {
        Http::fake();

        $this->assertNull(app(FontLibrary::class)->embeddedCss('../../etc/passwd'));
        $this->assertNull(app(FontLibrary::class)->embeddedCss("Roboto'); }"));

        Http::assertNothingSent();
    }

    public function test_downloads_can_be_turned_off()
    {
        config()->set('services.render.font_downloads', false);
        Http::fake();

        $this->assertNull(app(FontLibrary::class)->embeddedCss('Lobster'));
        $this->assertNotNull(app(FontLibrary::class)->embeddedCss('Inter'));

        Http::assertNothingSent();
    }

    /** @param  array<string, string>  $subsets  subset name => file id */
    private function googleCss(string $family, array $subsets): string
    {
        $slug = strtolower($family);
        $ranges = ['latin-ext' => 'U+0100-02BA', 'latin' => 'U+0000-00FF'];

        return collect($subsets)->map(fn ($id, $subset) => "/* {$subset} */\n@font-face {\n"
            ."  font-family: '{$family}';\n  font-style: normal;\n  font-weight: 400;\n"
            ."  src: url(https://fonts.gstatic.com/s/{$slug}/{$id}.woff2) format('woff2');\n"
            ."  unicode-range: {$ranges[$subset]};\n}\n")->implode('');
    }
}

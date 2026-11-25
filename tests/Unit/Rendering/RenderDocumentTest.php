<?php

namespace Tests\Unit\Rendering;

use App\Rendering\RenderDocument;
use App\Rendering\RenderWarnings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Before the HTML of a snap goes to the browser, the app embeds its fonts and
 * points its asset URLs at an address that the browser can reach. Then a render
 * needs no internet access.
 */
class RenderDocumentTest extends TestCase
{
    private const LINK = '<link href="https://fonts.googleapis.com/css2?family=Inter&family=DM+Serif+Display&family=Inter" rel="stylesheet">';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.render.font_cache_path', sys_get_temp_dir().'/render-document-test-'.uniqid());
        config()->set('app.url', 'https://app.snapsbrew.test');
        config()->set('app.previous_urls', ['https://old.snapsbrew.test']);
        config()->set('filesystems.disks.public.url', 'https://app.snapsbrew.test/storage');
        config()->set('media-library.disk_name', 'public');
        config()->set('services.render.asset_url', null);
    }

    public function test_the_google_fonts_link_becomes_embedded_font_faces()
    {
        Http::fake();

        $html = app(RenderDocument::class)->prepare($this->document(self::LINK));

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        // The link names Inter twice. Each rule of a family appears one time.
        $bundled = file_get_contents(resource_path('fonts/inter/font.css'));
        $this->assertSame(substr_count($bundled, '@font-face'), substr_count($html, "font-family: 'Inter';"));
        $this->assertStringContainsString("font-family: 'DM Serif Display';", $html);
        $this->assertStringContainsString('data:font/woff2;base64,', $html);
        $this->assertSame([], app(RenderWarnings::class)->all());
        Http::assertNothingSent();
    }

    public function test_a_missing_font_logs_a_warning_records_it_and_keeps_a_link_for_that_family_only()
    {
        config()->set('services.render.font_downloads', false);
        Log::spy();

        $html = app(RenderDocument::class)->prepare($this->document(
            '<link href="https://fonts.googleapis.com/css2?family=Inter&family=Fancy+Script" rel="stylesheet">'
        ));

        $this->assertStringContainsString('<link href="https://fonts.googleapis.com/css2?family=Fancy+Script" rel="stylesheet">', $html);
        $this->assertStringContainsString("font-family: 'Inter';", $html);
        $this->assertSame([[
            'code' => 'font_unavailable',
            'family' => 'Fancy Script',
            'message' => 'The font "Fancy Script" is not available on this server. The image can show a fallback font.',
        ]], app(RenderWarnings::class)->all());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn ($message, $context) => str_contains($message, 'font') && $context === ['family' => 'Fancy Script']
        );
    }

    public function test_a_company_custom_font_is_not_looked_up_on_google()
    {
        Http::fake();
        $custom = "<style>@font-face{font-family:'Brand Sans';src:url('https://app.snapsbrew.test/storage/1/brand.woff2');}</style>";

        $html = app(RenderDocument::class)->prepare($this->document(
            '<link href="https://fonts.googleapis.com/css2?family=Brand+Sans" rel="stylesheet">'.$custom
        ));

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringContainsString($custom, $html);
        $this->assertSame([], app(RenderWarnings::class)->all());
        Http::assertNothingSent();
    }

    public function test_an_empty_family_list_drops_the_link()
    {
        $html = app(RenderDocument::class)->prepare($this->document('<link href="https://fonts.googleapis.com/css2?family=" rel="stylesheet">'));

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
    }

    public function test_font_embedding_can_be_turned_off()
    {
        config()->set('services.render.embed_fonts', false);
        $document = $this->document(self::LINK);

        $this->assertSame($document, app(RenderDocument::class)->prepare($document));
    }

    public function test_asset_urls_point_at_the_render_asset_url_on_the_public_disk()
    {
        config()->set('services.render.asset_url', 'http://127.0.0.1:8080/');

        $html = app(RenderDocument::class)->prepare($this->document('', $this->assets()));

        $this->assertStringContainsString("background-image: url('http://127.0.0.1:8080/storage/1/photo.png')", $html);
        $this->assertStringContainsString("src:url('http://127.0.0.1:8080/storage/2/brand.woff2')", $html);
        $this->assertStringContainsString('<img src="http://127.0.0.1:8080/storage/3/logo.png">', $html);
        $this->assertStringContainsString('url(&quot;http://127.0.0.1:8080/storage/4/old.png&quot;)', $html);
    }

    public function test_the_rewrite_leaves_text_other_hosts_and_look_alike_hosts_alone()
    {
        config()->set('services.render.asset_url', 'http://127.0.0.1:8080');

        $html = app(RenderDocument::class)->prepare($this->document('', $this->assets()));

        $this->assertStringContainsString('Visit https://app.snapsbrew.test/pricing today', $html);
        $this->assertStringContainsString("url('https://cdn.example.com/a.png')", $html);
        $this->assertStringContainsString("url('https://app.snapsbrew.test.evil.com/b.png')", $html);
    }

    public function test_no_rewrite_without_a_render_asset_url()
    {
        $document = $this->document('', $this->assets());

        $this->assertSame($document, app(RenderDocument::class)->prepare($document));
    }

    public function test_no_rewrite_when_media_is_not_on_the_public_disk()
    {
        config()->set('services.render.asset_url', 'http://127.0.0.1:8080');
        config()->set('media-library.disk_name', 's3');
        $document = $this->document('', $this->assets());

        $this->assertSame($document, app(RenderDocument::class)->prepare($document));
    }

    private function assets(): string
    {
        return "<div style=\"background-image: url('https://app.snapsbrew.test/storage/1/photo.png');\"></div>"
            ."<style>@font-face{font-family:'Brand';src:url('https://app.snapsbrew.test/storage/2/brand.woff2');}</style>"
            .'<img src="https://app.snapsbrew.test/storage/3/logo.png">'
            .'<div style="background:url(&quot;https://old.snapsbrew.test/storage/4/old.png&quot;)"></div>'
            .'<p>Visit https://app.snapsbrew.test/pricing today</p>'
            ."<div style=\"background-image: url('https://cdn.example.com/a.png');\"></div>"
            ."<div style=\"background-image: url('https://app.snapsbrew.test.evil.com/b.png');\"></div>";
    }

    private function document(string $head, string $body = '<p>Hi</p>'): string
    {
        return '<!DOCTYPE html><html><head>'.$head.'</head><body>'.$body.'</body></html>';
    }
}

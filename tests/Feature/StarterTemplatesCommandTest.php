<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use App\Services\EditorPreflight;
use App\Support\StarterTemplates;
use App\Support\TemplateSizes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class StarterTemplatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeRenderer $renderer;

    /** The layer keys of each starter template, in paint order of the catalog. */
    private const EXPECTED_LAYERS = [
        'Starter: Link preview' => ['background', 'texture', 'accent_bar', 'eyebrow', 'headline', 'divider', 'site', 'read_time'],
        'Starter: Quote card' => ['background', 'quote_mark', 'quote', 'divider', 'author', 'role'],
        'Starter: Certificate' => ['background', 'outer_frame', 'inner_frame', 'eyebrow', 'intro', 'recipient', 'name_rule', 'description', 'date', 'date_rule', 'date_label', 'signature', 'signature_rule', 'signature_label', 'seal', 'seal_text'],
        'Starter: Product launch' => ['background', 'glow', 'texture', 'badge', 'badge_text', 'headline', 'subhead', 'cta', 'cta_text', 'brand'],
        'Starter: Blog header' => ['background', 'circle', 'circle_texture', 'category', 'title', 'byline'],
        'Starter: YouTube thumbnail' => ['background', 'badge', 'badge_text', 'headline', 'circle', 'circle_text'],
    ];

    private const EXPECTED_SIZES = [
        'Starter: Link preview' => ['open_graph', 1200, 630],
        'Starter: Quote card' => ['instagram_square_post', 1080, 1080],
        'Starter: Certificate' => ['print_a4_landscape', 3508, 2480],
        'Starter: Product launch' => ['instagram_post', 1080, 1350],
        'Starter: Blog header' => ['blog_header', 1600, 900],
        'Starter: YouTube thumbnail' => ['youtube_thumbnail', 1280, 720],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
    }

    private function userInNewCompany(array $attributes = []): User
    {
        return User::factory()->create(['company_id' => Company::factory()->create()->id] + $attributes);
    }

    private function starterTemplates(User $user)
    {
        return Template::query()
            ->with('publishedVersion')
            ->where('company_id', $user->company_id)
            ->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')
            ->get()
            ->keyBy('title');
    }

    public function test_the_command_adds_six_published_templates_to_the_company_of_the_owner()
    {
        $owner = $this->userInNewCompany(['is_owner' => true]);

        $this->artisan('snapsbrew:starter-templates')
            ->expectsOutputToContain('Added 6 starter templates. Skipped 0.')
            ->assertSuccessful();

        $templates = $this->starterTemplates($owner);

        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED_LAYERS), $templates->keys()->all());

        foreach ($templates as $title => $template) {
            [$size, $width, $height] = self::EXPECTED_SIZES[$title];

            $this->assertSame($owner->id, $template->user_id, $title);
            $this->assertSame($size, $template->size, $title);
            $this->assertSame([$width, $height], [(int) $template->width, (int) $template->height], $title);
            $this->assertNotNull($template->publishedVersion, "{$title} is not published.");
            $this->assertFalse($template->hasUnpublishedEdits(), $title);
            $this->assertSame(2, $template->editor_schema_version, $title);
            $this->assertTrue($template->hasMedia('preview'), "{$title} has no preview image.");
        }
    }

    public function test_the_command_is_idempotent()
    {
        $owner = $this->userInNewCompany(['is_owner' => true]);

        $this->artisan('snapsbrew:starter-templates')->assertSuccessful();
        $versions = $this->starterTemplates($owner)->sum(fn (Template $template) => $template->versions()->count());

        $this->artisan('snapsbrew:starter-templates')
            ->expectsOutputToContain('Added 0 starter templates. Skipped 6.')
            ->assertSuccessful();

        $this->assertCount(6, $this->starterTemplates($owner));
        $this->assertSame(6, Template::query()->where('company_id', $owner->company_id)->count());
        $this->assertSame($versions, $this->starterTemplates($owner)->sum(fn (Template $template) => $template->versions()->count()));
    }

    public function test_a_second_run_adds_back_only_the_missing_template()
    {
        $owner = $this->userInNewCompany(['is_owner' => true]);
        $this->artisan('snapsbrew:starter-templates')->assertSuccessful();

        $this->starterTemplates($owner)->get('Starter: Quote card')->delete();

        $this->artisan('snapsbrew:starter-templates')
            ->expectsOutputToContain('Added 1 starter templates. Skipped 5.')
            ->assertSuccessful();

        $this->assertCount(6, $this->starterTemplates($owner));
    }

    public function test_the_user_option_adds_the_templates_to_the_company_of_that_user()
    {
        $owner = $this->userInNewCompany(['is_owner' => true]);
        $member = $this->userInNewCompany(['email' => 'member@example.com']);

        $this->artisan('snapsbrew:starter-templates', ['--user' => 'member@example.com'])
            ->expectsOutputToContain('member@example.com')
            ->assertSuccessful();

        $templates = $this->starterTemplates($member);
        $this->assertCount(6, $templates);
        $this->assertTrue($templates->every(fn (Template $template) => $template->user_id === $member->id));
        $this->assertCount(0, $this->starterTemplates($owner));
    }

    public function test_an_unknown_user_fails_and_creates_nothing()
    {
        $this->userInNewCompany(['is_owner' => true]);

        $this->artisan('snapsbrew:starter-templates', ['--user' => 'nobody@example.com'])
            ->expectsOutputToContain('No user has the email nobody@example.com')
            ->assertFailed();

        $this->assertSame(0, Template::query()->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')->count());
    }

    public function test_without_an_owner_and_without_a_user_the_command_fails()
    {
        $this->userInNewCompany(['is_owner' => false]);

        $this->artisan('snapsbrew:starter-templates')
            ->expectsOutputToContain('This instance has no owner')
            ->assertFailed();

        $this->assertSame(0, Template::query()->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')->count());
    }

    public static function starterTitles(): array
    {
        return array_map(fn (string $title) => [$title], array_combine(array_keys(self::EXPECTED_LAYERS), array_keys(self::EXPECTED_LAYERS)));
    }

    #[DataProvider('starterTitles')]
    public function test_each_template_has_the_expected_layers_in_the_draft_the_version_and_the_editor_document(string $title)
    {
        $owner = $this->userInNewCompany(['is_owner' => true]);
        $this->artisan('snapsbrew:starter-templates')->assertSuccessful();

        $template = $this->starterTemplates($owner)->get($title);
        $expected = self::EXPECTED_LAYERS[$title];

        $this->assertSame($expected, array_column(json_decode($template->layers, true), 'key'));
        $this->assertSame($expected, array_column(json_decode($template->publishedVersion->layers, true), 'key'));
        $this->assertSame($expected, array_column($template->editor_document['layers'], 'key'));
        $this->assertSame([], $template->editor_document['groups']);
    }

    #[DataProvider('starterTitles')]
    public function test_the_editor_document_passes_the_preflight(string $title)
    {
        $owner = $this->userInNewCompany(['is_owner' => true]);
        $this->artisan('snapsbrew:starter-templates')->assertSuccessful();

        $template = $this->starterTemplates($owner)->get($title);

        $this->assertSame([], app(EditorPreflight::class)->inspect($template->editor_document));
        $this->assertSame([], app(EditorPreflight::class)->inspect($template->publishedVersion->editor_document));
    }

    #[DataProvider('starterTitles')]
    public function test_each_template_obeys_the_house_rules_of_the_design_guide(string $title)
    {
        $definition = collect(StarterTemplates::all())->firstWhere('title', $title);
        $size = TemplateSizes::find($definition['size']);
        $margin = TemplateSizes::safeMargin($size['width'], $size['height']);

        $fonts = [];

        foreach ($definition['layers'] as $layer) {
            $properties = collect($layer['properties'])->pluck('value', 'name');

            $this->assertArrayNotHasKey('backgroundImage', $properties->all(), "{$title}: {$layer['key']} uses an image.");

            if ($layer['type'] !== 'text') {
                continue;
            }

            $fonts[$properties['fontFamily']] = true;

            $this->assertGreaterThanOrEqual($margin, $properties['positionX'], "{$title}: {$layer['key']} starts in the left margin.");
            $this->assertGreaterThanOrEqual($margin, $properties['positionY'], "{$title}: {$layer['key']} starts in the top margin.");
            $this->assertLessThanOrEqual($size['width'] - $margin, $properties['positionX'] + $properties['width'], "{$title}: {$layer['key']} ends in the right margin.");
            $this->assertLessThanOrEqual($size['height'] - $margin, $properties['positionY'] + $properties['height'], "{$title}: {$layer['key']} ends in the bottom margin.");
        }

        $this->assertLessThanOrEqual(2, count($fonts), "{$title} uses more than two font families.");
    }

    public function test_each_template_renders_its_text_through_the_snap_builder()
    {
        $this->userInNewCompany(['is_owner' => true]);
        $this->artisan('snapsbrew:starter-templates')->assertSuccessful();

        $documents = implode("\n", $this->renderer->renderedDocuments);

        $this->assertStringContainsString('Say hello to Version 2.0', $documents);
        $this->assertStringContainsString('STOP DOING THIS', $documents);
        $this->assertStringContainsString('fonts.googleapis.com/css2?family=', $documents);
        $this->assertContains(['format' => 'png', 'transparent' => false, 'width' => 3508, 'height' => 2480], $this->renderer->screenshotCalls);
    }

    public function test_a_failed_preview_keeps_the_template_and_warns()
    {
        $owner = $this->userInNewCompany(['is_owner' => true]);

        $this->app->instance(SnapRenderer::class, new class extends FakeRenderer
        {
            public function screenshotBase64(string $html, int $width, int $height, string $format = 'png', bool $transparent = false): string
            {
                throw new RuntimeException('Chrome is not available.');
            }
        });

        $this->artisan('snapsbrew:starter-templates')
            ->expectsOutputToContain('did not render')
            ->assertSuccessful();

        $templates = $this->starterTemplates($owner);
        $this->assertCount(6, $templates);
        $this->assertTrue($templates->every(fn (Template $template) => $template->publishedVersion !== null));
    }
}

<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiKey;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Form;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use App\Support\ApiDocs\ApiSpec;
use App\Support\ApiDocs\MarkdownGenerator;
use App\Support\ApiDocs\OpenApiGenerator;
use App\Support\ApiDocs\PostmanGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * The app moves from https://snapsbrew.com to https://app.snapsbrew.com. The
 * root domain then sends every app path to the app host with a 308. A URL that
 * a third party stored before the move must keep working on the new host.
 */
class AppSubdomainTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 'https://snapsbrew.com';

    private const APP = 'https://app.snapsbrew.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => self::APP,
            'app.previous_urls' => [self::ROOT],
        ]);
    }

    public function test_a_signed_url_made_for_the_root_domain_renders_on_the_app_host()
    {
        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        [$user, $template, $plainKey] = $this->provisionSignedUrlKey();

        $query = $this->signedUrlQuery($plainKey, $template);

        $this->get(self::APP.'/signed-url/image.jpg?'.$query)->assertRedirect();

        $this->assertDatabaseHas('api_logs', [
            'user_id' => $user->id,
            'feature' => 'signed_url',
            'renders' => 1,
        ]);
    }

    public function test_a_render_cached_on_the_root_domain_is_served_on_the_app_host_without_a_new_render()
    {
        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        [$user, $template, $plainKey] = $this->provisionSignedUrlKey();

        $query = $this->signedUrlQuery($plainKey, $template);

        $this->get(self::ROOT.'/signed-url/image.jpg?'.$query)->assertRedirect();

        // The cache key of a snap rendered before the move is md5 of the full
        // root-domain URL. It must not change, or every cached image renders
        // again.
        $rootUrl = Request::create(self::ROOT.'/signed-url/image.jpg?'.$query)->fullUrl();
        $snap = Snap::where('template_id', $template->id)->sole();
        $this->assertSame(md5($rootUrl.'|v='.($template->fresh()->published_version_id ?? '')), $snap->identifier);

        $this->get(self::APP.'/signed-url/image.jpg?'.$query)->assertRedirect();

        $this->assertSame(1, Snap::where('template_id', $template->id)->count());
        $this->assertSame(1, (int) ApiLog::where('user_id', $user->id)->sum('renders'));
    }

    public function test_an_embedded_form_signed_on_the_root_domain_opens_on_the_app_host()
    {
        $form = $this->provisionForm();

        $rootSigned = $this->signOnRootDomain('signedEmbeddedForm', $form);

        $this->get($this->onAppHost($rootSigned))->assertOk();
    }

    public function test_an_embedded_form_page_posts_to_the_app_host()
    {
        $form = $this->provisionForm();
        $form->update([
            'signed_url' => $this->signOnRootDomain('signedEmbeddedForm', $form),
            'submit_signed_url' => $this->signOnRootDomain('submitSignedEmbeddedForm', $form),
        ]);

        $page = $this->get($this->onAppHost($form->signed_url))->assertOk();

        // A POST from the iframe to the root domain gets a 308. A CORS preflight
        // cannot follow a redirect, so the stored root-domain URL fails there.
        $submitUrl = $this->embeddedFormProp($page->getContent())['submit_signed_url'];
        $this->assertStringStartsWith(self::APP.'/api/v1/embedded-forms/'.$form->uuid.'?signature=', $submitUrl);
    }

    public function test_an_embedded_form_submission_signed_on_the_root_domain_is_accepted_on_the_app_host()
    {
        Storage::fake('public');
        $renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $renderer);

        $form = $this->provisionForm();

        $rootSigned = $this->signOnRootDomain('submitSignedEmbeddedForm', $form);

        // A bad signature answers 404 before the render.
        $this->postJson($this->onAppHost($rootSigned))->assertCreated();
        $this->assertCount(1, $renderer->screenshotCalls);
    }

    public function test_an_embedded_form_signed_for_another_host_is_rejected()
    {
        $form = $this->provisionForm();

        $foreign = $this->signOnHost('https://evil.example', 'signedEmbeddedForm', $form);

        $this->get(str_replace('https://evil.example', self::APP, $foreign))->assertUnauthorized();
        $this->get(self::APP.'/embedded-form/'.$form->uuid.'?signature=tampered')->assertUnauthorized();
        $this->postJson(self::APP.'/api/v1/embedded-forms/'.$form->uuid.'?signature=tampered')->assertNotFound();
    }

    public function test_the_api_docs_name_the_app_host()
    {
        $this->assertSame(self::APP.'/api', ApiSpec::info()['baseUrl']);

        $surfaces = [
            json_encode(ApiSpec::groups(), JSON_UNESCAPED_SLASHES),
            json_encode(ApiSpec::schemas(), JSON_UNESCAPED_SLASHES),
            app(OpenApiGenerator::class)->toYaml(),
            app(MarkdownGenerator::class)->full(),
            json_encode(app(PostmanGenerator::class)->toArray(), JSON_UNESCAPED_SLASHES),
        ];

        foreach ($surfaces as $surface) {
            $this->assertStringNotContainsString(self::ROOT.'/', $surface);
        }

        $this->assertStringContainsString(self::APP.'/api/v1/snaps', $surfaces[0]);
    }

    public function test_the_documentation_guides_name_the_app_host()
    {
        $guides = [
            'documentation/guides/mcp' => '/mcp',
            'documentation/guides/signed-urls' => '/signed-url/image.jpg',
            'documentation/guides/embedded-forms' => '/embedded-form/',
            'documentation' => '/documentation',
        ];

        foreach ($guides as $path => $expected) {
            $html = $this->get(self::APP.'/'.$path)->assertOk()->getContent();

            $this->assertStringContainsString(self::APP.$expected, $html, $path);
            $this->assertStringNotContainsString(self::ROOT.$expected, $html, $path);
        }
    }

    public function test_mcp_discovery_on_the_app_host_names_the_app_host()
    {
        $server = $this->getJson(self::APP.'/.well-known/oauth-authorization-server')->assertOk();

        $this->assertSame(self::APP, $server->json('issuer'));
        $this->assertSame(self::APP.'/oauth/authorize', $server->json('authorization_endpoint'));
        $this->assertSame(self::APP.'/oauth/token', $server->json('token_endpoint'));
        $this->assertSame(self::APP.'/oauth/register', $server->json('registration_endpoint'));

        $resource = $this->getJson(self::APP.'/.well-known/oauth-protected-resource/mcp')->assertOk();

        $this->assertSame(self::APP.'/mcp', $resource->json('resource'));
        $this->assertSame([self::APP], $resource->json('authorization_servers'));
    }

    /** @return array{0: User, 1: Template, 2: string} */
    private function provisionSignedUrlKey(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $plainKey = Str::random(40);
        ApiKey::factory()->signedUrl()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'key' => $plainKey,
        ]);

        return [$user, $template, $plainKey];
    }

    /** The query that a consumer signs. The host is not in the signed string. */
    private function signedUrlQuery(string $plainKey, Template $template): string
    {
        $params = [
            'template_id' => $template->uuid,
            'variants' => base64_encode(json_encode([])),
        ];

        $fields = collect($params)
            ->sortKeys()
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode('&');

        return http_build_query($params + ['signature' => hash_hmac('sha256', $fields, $plainKey)]);
    }

    private function provisionForm(): Form
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);

        return Form::factory()->forTemplate($template)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
    }

    /** Sign a route the way the app did while it ran on the root domain. */
    private function signOnRootDomain(string $route, Form $form): string
    {
        return $this->signOnHost(self::ROOT, $route, $form);
    }

    private function signOnHost(string $root, string $route, Form $form): string
    {
        // forceRootUrl() keeps the scheme of the current request, so force the
        // scheme too. Otherwise an http APP_URL signs an http URL.
        URL::forceRootUrl($root);
        URL::forceScheme('https');
        $signed = URL::signedRoute($route, ['formUuid' => $form->uuid]);
        URL::forceScheme(null);
        URL::forceRootUrl(null);

        return $signed;
    }

    /** The URL that the client requests after the 308 from the root domain. */
    private function onAppHost(string $rootUrl): string
    {
        $this->assertStringStartsWith(self::ROOT.'/', $rootUrl);

        return self::APP.substr($rootUrl, strlen(self::ROOT));
    }

    /** @return array<string, mixed> */
    private function embeddedFormProp(string $html): array
    {
        $this->assertSame(1, preg_match("/:form='([^']*)'/", $html, $match), 'The page has no embedded-form :form prop.');

        return json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
    }
}

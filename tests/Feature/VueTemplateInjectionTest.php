<?php

namespace Tests\Feature;

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Font;
use App\Models\Form;
use App\Models\Screenshot;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Tag;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * The app mounts one Vue app on `#app` with the compiler build. Vue compiles
 * the server HTML inside `#app` as a template, so a `{{ }}` expression in user
 * text runs as JavaScript. Blade escapes HTML but keeps the braces.
 *
 * Each page must print user text inside an element with `v-pre`, which Vue
 * does not compile. These tests fill every user field with a mustache and
 * fail when one reaches a text node in `#app` with no `v-pre` ancestor.
 */
class VueTemplateInjectionTest extends TestCase
{
    use RefreshDatabase;
    use WithPassportKeys;

    private const PAYLOAD = '{{ 7 * 191 }}';

    private User $owner;

    private Company $company;

    private Template $template;

    private TemplateCollection $collection;

    private Form $form;

    private Form $collectionForm;

    private Snap $snap;

    private Screenshot $screenshot;

    private ApiLog $apiLog;

    private SnapImport $import;

    protected function setUp(): void
    {
        parent::setUp();

        $p = self::PAYLOAD;

        $this->company = Company::factory()->create(['name' => "Company {$p}"]);
        $this->owner = User::factory()->create([
            'company_id' => $this->company->id,
            'name' => "Owner {$p}",
            'is_owner' => true,
        ]);
        User::factory()->create(['company_id' => $this->company->id, 'name' => "Member {$p}"]);
        User::factory()->create(['name' => "Stranger {$p}"]);

        $this->template = Template::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'title' => "Template {$p}",
            'layers' => json_encode([
                ['key' => 'headline', 'type' => 'text', 'title' => "Layer {$p}", 'properties' => [['name' => 'textContent', 'value' => "Text {$p}"]]],
            ]),
        ]);
        $tag = Tag::factory()->create(['company_id' => $this->company->id, 'name' => "Tag {$p}"]);
        $this->template->tags()->attach($tag);

        $this->collection = TemplateCollection::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'name' => "Collection {$p}",
        ]);
        $this->collection->templates()->attach($this->template);

        $this->form = Form::factory()->forTemplate($this->template)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'title' => "Form {$p}",
            'available_layers' => json_encode(['layers' => ['headline']]),
        ]);
        $this->collectionForm = Form::factory()->forCollection($this->collection)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'title' => "Collection form {$p}",
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => [['template_uuid' => $this->template->uuid, 'layer_keys' => ['headline']]],
            ]),
        ]);

        $this->snap = Snap::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'template_id' => $this->template->id,
            'form_id' => $this->form->id,
            'variants' => json_encode([['layer' => 'headline', 'text' => "Variant {$p}"]]),
        ]);

        $this->screenshot = Screenshot::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'url' => 'https://example.com/?q='.$p,
        ]);

        $this->apiLog = ApiLog::factory()->create([
            'user_id' => $this->owner->id,
            'url' => 'https://example.com/api/v1/snaps?q='.$p,
            'request' => json_encode(['template_id' => $this->template->uuid, 'text' => "Request {$p}"]),
            'response' => json_encode(['id' => $this->snap->uuid, 'text' => "Response {$p}"]),
        ]);

        $this->import = SnapImport::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'template_id' => $this->template->id,
            'title' => "Import {$p}",
            'original_filename' => "file {$p}.csv",
        ]);

        Font::factory()->create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'name' => "Font {$p}"]);
        CompanyInvitation::factory()->create([
            'company_id' => $this->company->id,
            'invited_by' => $this->owner->id,
            'email' => 'invitee+'.Str::random(4).'@example.com',
        ]);
        $this->owner->createToken("Token {$p}", ['api_requests']);
    }

    /**
     * Fails when a mustache reaches a text node inside `#app` that has no
     * `v-pre` ancestor. Attribute values are not checked: Vue does not
     * interpolate a plain attribute, and a bound attribute holds JSON.
     */
    private function assertNoCompiledMustache(string $html, string $page): void
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $app = $xpath->query('//*[@id="app"]')->item(0);

        if (! $app) {
            return;
        }

        $exposed = [];

        foreach ($xpath->query('.//text()[contains(., "{{")]', $app) as $node) {
            if ($xpath->query('ancestor::*[@v-pre]', $node)->length === 0) {
                $exposed[] = trim($node->parentNode->nodeName.': '.$node->textContent);
            }
        }

        $this->assertSame([], $exposed, "{$page} prints user text that Vue compiles.");

        // The other side of the trade: a component inside `v-pre` is never
        // mounted, so the page silently loses it.
        $muted = [];

        foreach (self::componentTags() as $tag) {
            foreach ($xpath->query("//{$tag}[ancestor::*[@v-pre]]") as $node) {
                $muted[] = $tag;
            }
        }

        $this->assertSame([], $muted, "{$page} puts a Vue component inside v-pre, so Vue never mounts it.");
    }

    /**
     * The kebab-case tag of every component that `resources/js/app.js`
     * registers, so a new component is checked without a change here.
     *
     * @return array<int, string>
     */
    private static function componentTags(): array
    {
        $source = file_get_contents(resource_path('js/app.js'));

        preg_match('/createApp\(\{\s*components:\s*\{([^}]*)\}/', $source, $block);
        preg_match_all("/app\.component\('([A-Za-z]+)'/", $source, $registered);

        $names = array_merge(
            preg_split('/[\s,]+/', trim($block[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
            $registered[1],
        );

        return array_values(array_unique(array_map(fn (string $name) => Str::kebab($name), $names)));
    }

    public static function dashboardPages(): array
    {
        return [
            'dashboard' => [fn (self $t) => '/dashboard'],
            'account' => [fn (self $t) => '/account'],
            'account users' => [fn (self $t) => '/account?tab=users'],
            'account developers' => [fn (self $t) => '/account?tab=developers'],
            'admin users' => [fn (self $t) => '/admin/users'],
            'admin settings' => [fn (self $t) => '/admin/settings'],
            'templates' => [fn (self $t) => '/templates'],
            'template show' => [fn (self $t) => '/templates/'.$t->template->uuid],
            'template edit' => [fn (self $t) => '/templates/'.$t->template->uuid.'/edit'],
            'template editor' => [fn (self $t) => '/templates/'.$t->template->uuid.'/editor'],
            'template generate' => [fn (self $t) => '/templates/'.$t->template->uuid.'/generate'],
            'template forms' => [fn (self $t) => '/templates/'.$t->template->uuid.'/forms'],
            'template form new' => [fn (self $t) => '/templates/'.$t->template->uuid.'/forms/new'],
            'template form' => [fn (self $t) => '/templates/'.$t->template->uuid.'/forms/'.$t->form->uuid],
            'template form edit' => [fn (self $t) => '/templates/'.$t->template->uuid.'/forms/'.$t->form->uuid.'/edit'],
            'imports' => [fn (self $t) => '/templates/'.$t->template->uuid.'/imports'],
            'import create' => [fn (self $t) => '/templates/'.$t->template->uuid.'/imports/create'],
            'import' => [fn (self $t) => '/templates/'.$t->template->uuid.'/imports/'.$t->import->uuid],
            'collections' => [fn (self $t) => '/collections'],
            'collections search' => [fn (self $t) => '/collections?q='.urlencode('{{ 7 * 191 }}')],
            'collection' => [fn (self $t) => '/collections/'.$t->collection->uuid],
            'collection forms' => [fn (self $t) => '/collections/'.$t->collection->uuid.'/forms'],
            'collection form new' => [fn (self $t) => '/collections/'.$t->collection->uuid.'/forms/new'],
            'collection form' => [fn (self $t) => '/collections/'.$t->collection->uuid.'/forms/'.$t->collectionForm->uuid],
            'collection form edit' => [fn (self $t) => '/collections/'.$t->collection->uuid.'/forms/'.$t->collectionForm->uuid.'/edit'],
            'collection add template' => [fn (self $t) => '/collections/'.$t->collection->uuid.'/templates/new'],
            'snapshots' => [fn (self $t) => '/snapshots'],
            'snapshot' => [fn (self $t) => '/snapshots/'.$t->snap->uuid],
            'screenshots' => [fn (self $t) => '/screenshots'],
            'screenshot' => [fn (self $t) => '/screenshots/'.$t->screenshot->uuid],
            'logs' => [fn (self $t) => '/logs'],
            'log' => [fn (self $t) => '/logs/'.$t->apiLog->uuid],
            'fonts' => [fn (self $t) => '/fonts'],
        ];
    }

    /**
     * @dataProvider dashboardPages
     */
    #[DataProvider('dashboardPages')]
    public function test_a_dashboard_page_does_not_compile_user_text(\Closure $url)
    {
        $this->withoutVite();
        $path = $url($this);

        $response = $this->actingAs($this->owner)->get($path);

        $this->assertSame(200, $response->getStatusCode(), "{$path} answered {$response->getStatusCode()}.");
        $this->assertStringContainsString('7 * 191', $response->getContent(), "{$path} does not print the payload, so the test proves nothing.");
        $this->assertNoCompiledMustache($response->getContent(), $path);
    }

    /**
     * These layouts put `v-pre` on the whole page content. The check proves
     * that no page of them loses a Vue component to it.
     */
    public function test_the_guest_auth_and_documentation_pages_keep_their_components()
    {
        $this->withoutVite();

        foreach (['/', '/login', '/register', '/forgot-password', '/documentation'] as $path) {
            $response = $this->get($path);

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $this->assertNoCompiledMustache($response->getContent(), $path);
        }

        $this->assertNoCompiledMustache($this->get('/documentation')->assertOk()->getContent(), '/documentation');
    }

    public function test_the_embedded_form_page_does_not_compile_user_text()
    {
        $this->withoutVite();

        $html = $this->get(URL::signedRoute('signedEmbeddedForm', ['formUuid' => $this->form->uuid]))
            ->assertOk()
            ->getContent();

        $this->assertNoCompiledMustache($html, 'embedded form');
    }

    public function test_the_oauth_consent_page_does_not_compile_the_client_name()
    {
        $this->setUpWithPassportKeys();
        $this->withoutVite();
        config(['mcp.redirect_domains' => ['https://claude.ai']]);

        $client = Client::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Client '.self::PAYLOAD,
            'secret' => null,
            'provider' => null,
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $html = $this->actingAs($this->owner)->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]))->assertOk()->getContent();

        $this->assertStringContainsString('7 * 191', $html);
        $this->assertNoCompiledMustache($html, 'oauth consent');
    }

    public function test_the_oauth_connections_list_does_not_compile_the_client_name()
    {
        $this->setUpWithPassportKeys();
        $this->withoutVite();

        $client = Client::forceCreate([
            'id' => (string) Str::uuid(),
            'name' => 'Client '.self::PAYLOAD,
            'secret' => null,
            'provider' => null,
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'revoked' => false,
        ]);
        Token::forceCreate([
            'id' => Str::random(80), 'user_id' => $this->owner->id, 'client_id' => $client->id,
            'name' => null, 'scopes' => ['mcp:use'], 'revoked' => false,
            'created_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addDay(),
        ]);

        $html = $this->actingAs($this->owner)->get('/account?tab=developers')->assertOk()->getContent();

        $this->assertStringContainsString('Client '.self::PAYLOAD, html_entity_decode($html));
        $this->assertNoCompiledMustache($html, 'oauth connections');
    }

    public function test_the_component_list_reads_the_registered_components()
    {
        $tags = self::componentTags();

        $this->assertContains('account-tabs', $tags);
        $this->assertContains('copy-text-button', $tags);
        $this->assertContains('sb-editor-label', $tags);
    }

    public function test_the_check_finds_a_component_inside_v_pre()
    {
        $this->expectException(ExpectationFailedException::class);

        $this->assertNoCompiledMustache('<div id="app"><div v-pre><copy-text-button></copy-text-button></div></div>', 'fixture');
    }

    public function test_the_check_finds_a_mustache_outside_v_pre()
    {
        $this->expectException(ExpectationFailedException::class);

        $this->assertNoCompiledMustache('<div id="app"><p v-pre>{{ a }}</p><p>{{ b }}</p></div>', 'fixture');
    }
}

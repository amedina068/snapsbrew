<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use App\Support\ApiDocs\ApiSpec;
use App\Support\ApiDocs\OpenApiGenerator;
use App\Support\DesignGuide;
use App\Support\EditorDocumentContract;
use App\Support\ImagePurposeGuide;
use App\Support\LayerSchema;
use App\Support\PlatformGuide;
use App\Support\VariantProperties;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The guide routes serve the same catalog classes the MCP tools serve.
 *
 * Every assertion compares the response against the catalog class itself,
 * because that class is the one source both surfaces read. A test that
 * repeated the catalog content would pass while the two surfaces drift.
 *
 * These routes read no company and no model, so they carry no tenant
 * boundary. This file asserts the token gate only.
 */
class GuideApiTest extends TestCase
{
    use RefreshDatabase;

    private function createApiUser(): string
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return $user->createToken('test', ['api_requests'])->plainTextToken;
    }

    private function getAsApiUser(string $path)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->createApiUser())
            ->getJson($path);
    }

    public function test_design_returns_the_same_catalog_the_mcp_tool_returns()
    {
        $response = $this->getAsApiUser('/api/v1/guides/design');

        $response->assertStatus(200);
        $this->assertSame(['data' => DesignGuide::catalog()], $response->json());
        $this->assertArrayHasKey('canvas_presets', $response->json('data'));
    }

    public function test_layers_returns_the_same_catalog_the_mcp_tool_returns()
    {
        $response = $this->getAsApiUser('/api/v1/guides/layers');

        $response->assertStatus(200);
        $this->assertSame(['data' => LayerSchema::catalog()], $response->json());
        $this->assertArrayHasKey('value_shapes', $response->json('data'));
    }

    public function test_variant_properties_returns_the_same_catalog_the_mcp_tool_returns()
    {
        $response = $this->getAsApiUser('/api/v1/guides/variant-properties');

        $response->assertStatus(200);
        $this->assertSame(['data' => VariantProperties::catalog()], $response->json());
        $this->assertContains('text', array_column($response->json('data'), 'property'));
    }

    public function test_editor_document_returns_the_same_catalog_the_mcp_tool_returns()
    {
        $response = $this->getAsApiUser('/api/v1/guides/editor-document');

        $response->assertStatus(200);
        $this->assertSame(['data' => EditorDocumentContract::catalog()], $response->json());
        $this->assertArrayHasKey('contract_version', $response->json('data'));
    }

    public function test_platforms_returns_the_index_the_mcp_tool_returns()
    {
        $response = $this->getAsApiUser('/api/v1/guides/platforms');

        $response->assertStatus(200);
        $this->assertSame([
            'data' => PlatformGuide::index(),
            'specs_reviewed' => PlatformGuide::SPECS_REVIEWED,
        ], $response->json());
        $this->assertSame(PlatformGuide::keys(), array_column($response->json('data'), 'platform'));
    }

    public function test_purposes_returns_the_index_the_mcp_tool_returns()
    {
        $response = $this->getAsApiUser('/api/v1/guides/purposes');

        $response->assertStatus(200);
        $this->assertSame(['data' => ImagePurposeGuide::index()], $response->json());
        $this->assertSame(ImagePurposeGuide::keys(), array_column($response->json('data'), 'purpose'));
    }

    /**
     * The detail route resolves an alias the same way the MCP tool does, so a
     * caller can send the word a human types.
     */
    public function test_a_platform_alias_returns_the_canonical_guide()
    {
        $canonical = $this->getAsApiUser('/api/v1/guides/platforms/instagram');
        $canonical->assertStatus(200);
        $this->assertSame(['data' => PlatformGuide::find('instagram')], $canonical->json());

        foreach (['insta' => 'instagram', 'ig' => 'instagram', 'twitter' => 'x', 'og' => 'web'] as $alias => $key) {
            $response = $this->getAsApiUser('/api/v1/guides/platforms/'.$alias);

            $response->assertStatus(200);
            $this->assertSame(['data' => PlatformGuide::find($key)], $response->json());
        }

        $this->assertSame($canonical->json(), $this->getAsApiUser('/api/v1/guides/platforms/insta')->json());
    }

    public function test_a_purpose_alias_returns_the_canonical_guide()
    {
        foreach (['og' => 'link_preview', 'gallery' => 'carousel', 'thumbnail' => 'video_thumbnail'] as $alias => $key) {
            $response = $this->getAsApiUser('/api/v1/guides/purposes/'.$alias);

            $response->assertStatus(200);
            $this->assertSame(['data' => ImagePurposeGuide::find($key)], $response->json());
        }
    }

    public function test_an_unknown_platform_returns_a_404_that_names_the_valid_keys()
    {
        $response = $this->getAsApiUser('/api/v1/guides/platforms/myspace');

        $response->assertStatus(404);
        $this->assertSame('No platform guide matches `myspace`.', $response->json('message'));
        $this->assertSame(PlatformGuide::keys(), $response->json('available'));
        $this->assertContains('instagram', $response->json('available'));
    }

    public function test_an_unknown_purpose_returns_a_404_that_names_the_valid_keys()
    {
        $response = $this->getAsApiUser('/api/v1/guides/purposes/wallpaper');

        $response->assertStatus(404);
        $this->assertSame('No purpose guide matches `wallpaper`.', $response->json('message'));
        $this->assertSame(ImagePurposeGuide::keys(), $response->json('available'));
        $this->assertContains('link_preview', $response->json('available'));
    }

    /**
     * The `Accept` header is sent by hand here. A real client sends it, and it
     * is the header that turns the failure into a 422 with an `errors` body.
     */
    #[DataProvider('detailRoutesAndTheirParameter')]
    public function test_a_guide_key_longer_than_64_characters_fails_validation(string $path, string $field)
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->createApiUser(),
            'Accept' => 'application/json',
        ])->get($path.str_repeat('a', 65));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors($field);
        $this->assertStringContainsString(
            'must not be greater than 64 characters',
            $response->json('errors.'.$field.'.0')
        );
    }

    public static function detailRoutesAndTheirParameter(): array
    {
        return [
            'platform' => ['/api/v1/guides/platforms/', 'platform'],
            'purpose' => ['/api/v1/guides/purposes/', 'purpose'],
        ];
    }

    #[DataProvider('guidePaths')]
    public function test_a_guide_route_requires_a_token(string $path)
    {
        $this->getJson($path)->assertStatus(401);

        $this->getAsApiUser($path)->assertStatus(200);
    }

    /**
     * A guide call can never be billed, so it must not enter the usage record
     * the customer reads.
     */
    public function test_a_guide_route_writes_no_api_log_row()
    {
        foreach (array_column($this->guidePaths(), 0) as $path) {
            $this->getAsApiUser($path)->assertStatus(200);
        }

        $this->assertDatabaseCount('api_logs', 0);
    }

    /**
     * `ApiSpec` generates the public docs screen and the OpenAPI schema. A route
     * that is absent from the spec is a route no customer can find.
     */
    public function test_the_guides_group_reaches_every_generated_doc_surface()
    {
        $paths = array_column(ApiSpec::group('guides')['endpoints'], 'path');
        $this->assertSame(
            [
                '/v1/guides/design',
                '/v1/guides/layers',
                '/v1/guides/variant-properties',
                '/v1/guides/editor-document',
                '/v1/guides/platforms',
                '/v1/guides/platforms/{platform}',
                '/v1/guides/purposes',
                '/v1/guides/purposes/{purpose}',
            ],
            $paths
        );

        $openapi = (new OpenApiGenerator)->toArray();
        $screen = $this->get('/documentation/guides')->assertStatus(200);

        foreach ($paths as $path) {
            $this->assertArrayHasKey($path, $openapi['paths']);
            $screen->assertSee($path);
        }
    }

    public static function guidePaths(): array
    {
        return [
            'design' => ['/api/v1/guides/design'],
            'layers' => ['/api/v1/guides/layers'],
            'variant properties' => ['/api/v1/guides/variant-properties'],
            'editor document' => ['/api/v1/guides/editor-document'],
            'platforms' => ['/api/v1/guides/platforms'],
            'one platform' => ['/api/v1/guides/platforms/instagram'],
            'purposes' => ['/api/v1/guides/purposes'],
            'one purpose' => ['/api/v1/guides/purposes/link_preview'],
        ];
    }
}

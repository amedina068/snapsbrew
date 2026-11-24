<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\User;
use App\Support\ApiDocs\ApiSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each error response that `ApiSpec` documents is one that the route really
 * returns, with the body that the example shows.
 *
 * An SDK builds a typed error from each documented status. A status that the
 * route never returns is a type that no caller can reach. The throttles of the
 * signed URL route and the embedded form route have their own tests:
 * `SignedUrlReservedParamsTest` and `EmbeddedFormRateLimitTest`.
 */
class DocumentedErrorResponsesTest extends TestCase
{
    use RefreshDatabase;

    /** A debug body adds the trace, and a client in production never reads one. */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /**
     * Each bearer group of `routes/api.php` has its own middleware chain, so
     * the test reads one route of each chain.
     */
    public function test_a_token_without_the_api_requests_ability_gets_the_documented_403()
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $token = $user->createToken('test', ['other'])->plainTextToken;

        foreach (['auth.show', 'guides.design', 'templates.index', 'media.index', 'preview.snap'] as $id) {
            $endpoint = $this->endpoint($id);

            $this->withToken($token)
                ->getJson('/api'.str_replace('{templateUuid}', 'any', $endpoint['path']))
                ->assertForbidden()
                ->assertExactJson($this->example($endpoint, 403));
        }
    }

    public function test_a_list_that_validates_its_query_returns_the_documented_422()
    {
        $token = $this->token();

        foreach (['templates.index', 'screenshots.index'] as $id) {
            $endpoint = $this->endpoint($id);

            $this->withToken($token)
                ->getJson('/api'.$endpoint['path'].'?cursor[]=1')
                ->assertUnprocessable()
                ->assertExactJson($this->example($endpoint, 422));
        }
    }

    /**
     * A 422 from the validator carries `errors` beside `message`. An example
     * with `message` only tells a client that the field errors are absent.
     */
    public function test_a_validator_422_returns_the_documented_body()
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;
        $tooLong = str_repeat('a', 65);

        $requests = [
            'guides.platform' => ['GET', "/api/v1/guides/platforms/{$tooLong}", []],
            'guides.purpose' => ['GET', "/api/v1/guides/purposes/{$tooLong}", []],
            'preview.template' => ['GET', "/api/v1/templates/{$template->uuid}/preview", ['variants' => [['layer' => 'logo', 'image_url' => 'http://127.0.0.1/logo.png']]]],
            'preview.snap' => ['GET', '/api/v1/snaps/preview', []],
            'tags.store' => ['POST', '/api/v1/tags', []],
            'tags.template.store' => ['POST', "/api/v1/templates/{$template->uuid}/tags", ['tag_ids' => [999]]],
        ];

        foreach ($requests as $id => [$method, $path, $body]) {
            $this->withToken($token)
                ->json($method, $path, $body)
                ->assertUnprocessable()
                ->assertExactJson($this->example($this->endpoint($id), 422));
        }
    }

    public function test_a_screenshot_with_a_bad_width_returns_the_documented_422()
    {
        $this->withToken($this->token())
            ->postJson('/api/v1/screenshots', ['url' => 'https://example.com', 'width' => 0])
            ->assertUnprocessable()
            ->assertExactJson($this->example($this->endpoint('screenshots.store'), 422));
    }

    public function test_a_template_update_with_no_title_returns_the_documented_422()
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $this->withToken($user->createToken('test', ['api_requests'])->plainTextToken)
            ->patchJson('/api/v1/templates/'.$template->uuid, ['width' => 100])
            ->assertUnprocessable()
            ->assertExactJson($this->example($this->endpoint('templates.update'), 422));
    }

    /**
     * A route that finds its record with `firstOrFail()` answers with the
     * message of `ModelNotFoundException`, which names the model class.
     */
    public function test_an_unknown_id_returns_the_documented_404()
    {
        $token = $this->token();
        $unknown = '00000000-0000-4000-8000-000000000000';

        $bodies = [
            'templates.update' => ['title' => 'Launch card', 'width' => 100, 'height' => 100],
            'editor-documents.update' => ['revision' => 0, 'document' => ['version' => 2]],
            'editor-documents.apply' => ['revision' => 0, 'operations' => [['op' => 'set_layer_property']]],
            'tags.template.store' => ['tag_ids' => []],
        ];

        foreach (['templates.show', 'templates.update', 'templates.destroy', 'templates.publish', 'editor-documents.show', 'editor-documents.update', 'editor-documents.apply', 'editor-documents.preflight', 'screenshots.show', 'preview.template', 'tags.template.store'] as $id) {
            $endpoint = $this->endpoint($id);
            $path = preg_replace('/\{[A-Za-z]+\}/', $unknown, $endpoint['path']);

            $this->withToken($token)
                ->json($endpoint['method'], '/api'.$path, $bodies[$id] ?? [])
                ->assertNotFound()
                ->assertExactJson($this->example($endpoint, 404));
        }
    }

    /**
     * The middleware finds the form before the controller reads the signature,
     * so an unknown form answers with the message of `ModelNotFoundException`.
     */
    public function test_an_unknown_embedded_form_returns_the_documented_404()
    {
        $this->postJson('/api/v1/embedded-forms/00000000-0000-4000-8000-000000000000?signature=invalid')
            ->assertNotFound()
            ->assertExactJson($this->example($this->endpoint('embedded-forms.store'), 404));
    }

    public function test_an_embedded_form_with_a_bad_signature_returns_404_with_the_documented_message()
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);
        $form = Form::factory()->forTemplate($template)->create(['company_id' => $user->company_id, 'user_id' => $user->id]);

        $this->postJson("/api/v1/embedded-forms/{$form->uuid}?signature=invalid")
            ->assertNotFound()
            ->assertExactJson(['message' => '']);

        $description = collect($this->endpoint('embedded-forms.store')['responses'])->firstWhere('status', 404)['description'];
        $this->assertStringContainsString('an empty `message`', $description);
    }

    private function token(): string
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        return $user->createToken('test', ['api_requests'])->plainTextToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function endpoint(string $id): array
    {
        $endpoint = collect(ApiSpec::endpoints())->firstWhere('id', $id);

        $this->assertNotNull($endpoint, "ApiSpec has no endpoint [{$id}].");

        return $endpoint;
    }

    /**
     * @param  array<string, mixed>  $endpoint
     * @return array<string, mixed>
     */
    private function example(array $endpoint, int $status): array
    {
        $response = collect($endpoint['responses'])->firstWhere('status', $status);

        $this->assertNotNull($response, "ApiSpec [{$endpoint['id']}] documents no {$status}.");

        return $response['example'];
    }
}

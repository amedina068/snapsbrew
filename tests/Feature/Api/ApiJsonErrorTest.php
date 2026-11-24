<?php

namespace Tests\Feature\Api;

use App\Exceptions\EditorOperationRejected;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every API error is a JSON body with a `message`.
 *
 * A client that did not send `Accept: application/json` got a 302 to /login
 * for a bad token, a 302 back for a validation error, and an HTML page for a
 * missing record. The first three tests send no Accept header on purpose.
 */
class ApiJsonErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_a_missing_token_answers_401_json_without_an_accept_header(): void
    {
        $this->call('GET', '/api/v1/auth')
            ->assertStatus(401)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_a_validation_error_answers_422_json_without_an_accept_header(): void
    {
        $user = $this->user();

        $this->withToken($this->token($user))
            ->post('/api/v1/templates', ['width' => 800, 'height' => 600])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_a_missing_record_answers_404_json_without_an_accept_header(): void
    {
        $user = $this->user();

        $this->withToken($this->token($user))
            ->get('/api/v1/templates/00000000-0000-0000-0000-000000000000')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }

    public function test_a_legacy_edit_of_a_v2_template_answers_a_code_and_a_message(): void
    {
        [$user, $template] = $this->templateWithDocument();

        $response = $this->withToken($this->token($user))
            ->patchJson("/api/v1/templates/{$template->uuid}", ['title' => 'New title'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'legacy_edit_rejected_for_v2');

        $this->assertStringContainsString('/v1/templates/{templateUuid}/document', $response->json('message'));
    }

    public function test_a_rejected_operation_answers_a_code_and_a_message(): void
    {
        [$user, $template] = $this->templateWithDocument();

        $response = $this->withToken($this->token($user))
            ->patchJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 0,
                'operations' => [['operation' => 'set_layer_property', 'layer_key' => 'missing', 'property' => 'imageFit', 'value' => 'cover']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'editor_operation_layer_not_found');

        $this->assertStringContainsString('layer_key', $response->json('message'));
    }

    public function test_an_operation_on_a_template_with_no_document_answers_a_code_and_a_message(): void
    {
        $user = $this->user();
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $response = $this->withToken($this->token($user))
            ->patchJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 0,
                'operations' => [['operation' => 'set_layer_type', 'layer_key' => 'hero', 'type' => 'text']],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'editor_operation_document_missing');

        $this->assertStringContainsString('PUT', $response->json('message'));
    }

    public function test_a_document_conflict_answers_a_message_next_to_the_current_state(): void
    {
        [$user, $template] = $this->templateWithDocument(revision: 3);

        $response = $this->withToken($this->token($user))
            ->putJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 2,
                'document' => ['version' => 2, 'layers' => [], 'groups' => []],
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'editor_document_conflict')
            ->assertJsonPath('current_revision', 3);

        $this->assertStringContainsString('current_revision', $response->json('message'));
    }

    /**
     * An unknown code falls back to a generic sentence. Each code that the
     * applier throws must have its own sentence.
     */
    public function test_every_operation_code_has_its_own_message(): void
    {
        $source = file_get_contents(app_path('Editor/EditorDocumentOperationApplier.php'));
        preg_match_all("/'(editor_operation_[a-z_]+)'/", $source, $matches);
        $codes = array_unique([...$matches[1], 'editor_operation_document_missing']);

        $this->assertNotEmpty($matches[1]);

        foreach ($codes as $code) {
            $this->assertNotSame(
                EditorOperationRejected::messageFor('editor_operation_not_a_code'),
                EditorOperationRejected::messageFor($code),
                "{$code} has no message."
            );
        }
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => Company::factory()->create()->id]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test', ['api_requests'])->plainTextToken;
    }

    /** @return array{0: User, 1: Template} */
    private function templateWithDocument(int $revision = 0): array
    {
        $user = $this->user();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'editor_document' => ['version' => 2, 'layers' => [['key' => 'hero', 'type' => 'text', 'properties' => []]], 'groups' => []],
            'editor_revision' => $revision,
            'editor_schema_version' => 2,
        ]);

        return [$user, $template];
    }
}

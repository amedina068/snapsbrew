<?php

namespace Tests\Feature\Api;

use App\Exceptions\EditorDocumentConflict;
use App\Exceptions\EditorOperationRejected;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use App\Support\ApiDocs\ApiSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TemplateDocumentApplyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The route carries two operation kinds, so the assertion reads the whole
     * document. An assertion over one layer stays green when the applier drops
     * every operation of the other kind.
     */
    public function test_apply_applies_the_operations_and_bumps_the_revision(): void
    {
        [$user, $template] = $this->templateWithDocument();
        $expected = $this->document();
        $expected['layers'][0]['properties'][] = ['name' => 'imageFit', 'value' => 'cover'];
        $expected['layers'][1]['type'] = 'qr_code';

        $this->apply($user, $template, [
            'revision' => 0,
            'operations' => [
                ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFit', 'value' => 'cover'],
                ['operation' => 'set_layer_type', 'layer_key' => 'headline', 'type' => 'qr_code'],
            ],
        ])
            ->assertOk()
            ->assertExactJson(['document' => $expected, 'revision' => 1]);

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'editor_revision' => 1, 'editor_schema_version' => 2]);
    }

    /**
     * A stale call answers 409 and leaves the row exactly as it was.
     *
     * The response half and the row half are two separate claims. A write that
     * escapes the transaction, for example a write in the catch block, survives
     * the rollback and still answers 409. The response alone cannot tell an
     * intact row from an overwritten one, so the four columns `replace()`
     * writes are read back from the raw row.
     */
    public function test_apply_returns_the_canonical_conflict_on_a_stale_revision(): void
    {
        [$user, $template] = $this->templateWithDocument(3);
        $template->forceFill([
            // Both columns hold a value the projection would replace. With a
            // null on each side an overwritten row still reads as unchanged.
            'layers' => json_encode([['key' => 'hero', 'properties' => []]]),
            'groups' => json_encode([['id' => 'g1', 'name' => 'Header']]),
        ])->save();

        // Read the stored row, not the model, so no cast hides a changed value.
        $before = DB::table('templates')->where('id', $template->id)->first();

        $this->apply($user, $template, $this->setImageFit(2))
            ->assertStatus(409)
            ->assertExactJson([
                'code' => 'editor_document_conflict',
                'message' => EditorDocumentConflict::MESSAGE,
                'current_revision' => 3,
                'current_document' => $this->document(),
            ]);

        $after = DB::table('templates')->where('id', $template->id)->first();

        $this->assertSame($before->editor_document, $after->editor_document);
        $this->assertSame($before->editor_revision, $after->editor_revision);
        $this->assertSame($before->layers, $after->layers);
        $this->assertSame($before->groups, $after->groups);
    }

    /**
     * The ordering ruling for a request that is stale and malformed at once.
     *
     * A malformed document is malformed at every revision, so the body error
     * answers first. A caller that reads 409 rereads the document and sends the
     * same bad body again, and the second call fails for the same reason. A
     * caller that reads 422 repairs the body one time.
     *
     * The twin case stays unchanged: a stale request with a well-formed body
     * still answers 409. Read
     * test_apply_returns_the_canonical_conflict_on_a_stale_revision above.
     *
     * This test also pins the lock duration. `replace()` validates before it
     * takes `lockForUpdate()`, so the host lookup inside SafeLayers runs outside
     * the lock. If the validation moves back inside the lock, the answer here
     * becomes 409 and this test fails.
     */
    public function test_a_stale_and_malformed_request_answers_with_the_body_error(): void
    {
        [$user, $template] = $this->templateWithUnsafeDocument(3);

        $response = $this->apply($user, $template, $this->setImageFit(2))
            ->assertStatus(422)
            ->assertJsonValidationErrors('layers');

        $this->assertStringContainsString(
            'background image points to an internal, reserved, or unsafe address',
            $response->json('errors.layers.0'),
        );

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'editor_revision' => 3]);
    }

    public function test_apply_is_scoped_to_the_callers_company(): void
    {
        [$user] = $this->templateWithDocument();
        $stranger = Template::factory()->create(['editor_document' => $this->document(), 'editor_revision' => 0]);

        $this->apply($user, $stranger, $this->setImageFit(0))->assertNotFound();
    }

    public function test_a_company_peer_who_does_not_own_the_template_can_still_apply_operations(): void
    {
        [$owner, $template] = $this->templateWithDocument();
        $peer = User::factory()->create(['company_id' => $owner->company_id]);

        $this->apply($peer, $template, $this->setImageFit(0))
            ->assertOk()
            ->assertJsonPath('revision', 1);
    }

    public function test_apply_rejects_a_body_without_operations(): void
    {
        [$user, $template] = $this->templateWithDocument();

        $response = $this->apply($user, $template, ['revision' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('operations');

        $this->assertStringContainsString('operations field is required', $response->json('errors.operations.0'));
    }

    public function test_apply_rejects_an_operation_the_contract_does_not_allow(): void
    {
        [$user, $template] = $this->templateWithDocument();

        $this->apply($user, $template, $this->setImageFit(0, 'ghost'))
            ->assertStatus(422)
            ->assertExactJson([
                'code' => 'editor_operation_layer_not_found',
                'message' => EditorOperationRejected::messageFor('editor_operation_layer_not_found'),
            ]);

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'editor_revision' => 0]);
    }

    public function test_apply_reports_a_template_that_has_no_editor_document(): void
    {
        [$user] = $this->templateWithDocument();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'editor_document' => null,
            'editor_revision' => 0,
        ]);

        $this->apply($user, $template, $this->setImageFit(0))
            ->assertStatus(422)
            ->assertExactJson([
                'code' => 'editor_operation_document_missing',
                'message' => EditorOperationRejected::messageFor('editor_operation_document_missing'),
            ]);
    }

    /**
     * A route the specification does not describe is a route no generated
     * surface carries. The OpenAPI schema, the Postman collection and the
     * Markdown all build from `ApiSpec`, so one reading of the spec proves all
     * three.
     */
    public function test_the_spec_describes_every_editor_document_route(): void
    {
        $described = collect(ApiSpec::endpoints())->where('group', 'editor-documents');

        // `OpenApiGenerator` derives the operationId from the id, and the
        // command line interface compares that key, so the id is a contract.
        $this->assertSame([
            'editor-documents.show',
            'editor-documents.apply',
            'editor-documents.update',
            'editor-documents.preflight',
        ], $described->pluck('id')->values()->all());

        $this->assertSame([
            'GET /v1/templates/{templateUuid}/document',
            'PATCH /v1/templates/{templateUuid}/document',
            'PUT /v1/templates/{templateUuid}/document',
            'GET /v1/templates/{templateUuid}/document/preflight',
        ], $described->map(fn (array $endpoint) => $endpoint['method'].' '.$endpoint['path'])->values()->all());
    }

    /**
     * The screen, the Markdown page and the OpenAPI schema all build from the
     * spec. The schema match reads whole lines, because a substring match
     * accepts every wrong name that keeps the right name as a prefix.
     */
    public function test_the_generated_surfaces_carry_the_editor_document_group(): void
    {
        $this->get('/documentation/editor-documents')->assertOk()->assertSee('Apply editor operations');
        $this->get('/documentation/editor-documents.md')->assertOk()->assertSee('PATCH /v1/templates/{templateUuid}/document');

        $schema = $this->get('/documentation/openapi.yaml')->assertOk()->getContent();
        $lines = array_map('trim', explode("\n", $schema));
        foreach (['show', 'apply', 'update', 'preflight'] as $operation) {
            $this->assertContains("operationId: editor_documents_{$operation}", $lines);
        }
    }

    /** @return array<int, mixed> The owner and a template that holds the fixture document. */
    private function templateWithDocument(int $revision = 0): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'editor_document' => $this->document(),
            'editor_revision' => $revision,
            'editor_schema_version' => 2,
        ]);

        return [$user, $template];
    }

    /**
     * The owner and a template whose stored document holds a layer that
     * SafeLayers rejects. The address is a literal IP, so the rule reads no DNS
     * and the test stays hermetic.
     *
     * @return array<int, mixed>
     */
    private function templateWithUnsafeDocument(int $revision): array
    {
        [$user, $template] = $this->templateWithDocument($revision);
        $document = $this->document();
        $document['layers'][0]['properties'][] = [
            'name' => 'backgroundImage',
            'value' => 'http://169.254.169.254/latest/meta-data/',
        ];
        $template->forceFill(['editor_document' => $document])->save();

        return [$user, $template];
    }

    /** @return array<string, mixed> A body that sets imageFit on one layer. */
    private function setImageFit(int $revision, string $layerKey = 'hero'): array
    {
        return ['revision' => $revision, 'operations' => [
            ['operation' => 'set_layer_property', 'layer_key' => $layerKey, 'property' => 'imageFit', 'value' => 'cover'],
        ]];
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return ['version' => 2, 'layers' => [
            ['key' => 'hero', 'type' => 'image', 'properties' => []],
            ['key' => 'headline', 'type' => 'text', 'properties' => []],
        ]];
    }

    private function apply(User $user, Template $template, array $body)
    {
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;

        return $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->uuid}/document", $body);
    }
}

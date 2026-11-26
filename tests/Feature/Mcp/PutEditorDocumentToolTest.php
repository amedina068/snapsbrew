<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use App\Rules\ValidLayers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

class PutEditorDocumentToolTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    /**
     * A stale call answers the conflict and leaves the row exactly as it was.
     *
     * The response half and the row half are two separate claims. A write that
     * escapes the transaction, for example a write in the catch block, survives
     * the rollback and still answers the conflict. The response alone cannot
     * tell an intact row from an overwritten one, so the four columns
     * `replace()` writes are read back from the raw row.
     */
    public function test_it_returns_the_current_document_on_a_stale_revision(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $document = ['version' => 2, 'layers' => [['key' => 'hero', 'type' => 'image', 'properties' => []]]];
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'editor_document' => $document,
            'editor_revision' => 3,
            'editor_schema_version' => 2,
            // Both columns hold a value the projection would replace. With a
            // null on each side an overwritten row still reads as unchanged.
            'layers' => json_encode([['key' => 'hero', 'properties' => []]]),
            'groups' => json_encode([['id' => 'g1', 'name' => 'Header']]),
        ]);
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        // Read the stored row, not the model, so no cast hides a changed value.
        $before = DB::table('templates')->where('id', $template->id)->first();

        // The body must be well formed to reach the revision check. `replace()`
        // validates the request document before it locks the row, so a stale
        // and malformed request answers a validation error. This test guards
        // the conflict, so the layer carries the shape the rules require.
        $response = $this->callMcpTool($token, 'put-editor-document', [
            'template_id' => $template->uuid,
            'revision' => 2,
            'document' => ['version' => 2, 'layers' => [['key' => 'stale', 'type' => 'text', 'properties' => []]]],
        ]);

        // The conflict is a tool error with the same shape as the conflict of
        // apply-editor-operations: `isError`, a message, a fix and the current
        // state under `details`.
        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $error = $this->mcpToolError($response);
        $this->assertSame('editor_document_conflict', $error['code']);
        $this->assertStringContainsString('get-editor-document', $error['fix']);
        $this->assertSame(3, $error['details']['current_revision']);
        // MySQL returns a JSON column with its own key order, so compare with the stored document.
        $this->assertSame($template->fresh()->editor_document, $error['details']['current_document']);
        $this->assertEquals($document, $error['details']['current_document']);

        $after = DB::table('templates')->where('id', $template->id)->first();

        $this->assertSame($before->editor_document, $after->editor_document);
        $this->assertSame($before->editor_revision, $after->editor_revision);
        $this->assertSame($before->layers, $after->layers);
        $this->assertSame($before->groups, $after->groups);
    }

    /**
     * `put-editor-document` validates `layers` with ValidLayers, so a document
     * over the layer cap is refused before the row is locked. The row half is
     * read back raw for the same reason as the conflict test above.
     */
    public function test_a_document_over_the_layer_cap_is_rejected_on_save(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $document = ['version' => 2, 'layers' => [['key' => 'hero', 'type' => 'image', 'properties' => []]]];
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'editor_document' => $document,
            'editor_revision' => 3,
            'editor_schema_version' => 2,
            'layers' => json_encode([['key' => 'hero', 'properties' => []]]),
            'groups' => json_encode([['id' => 'g1', 'name' => 'Header']]),
        ]);
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        $before = DB::table('templates')->where('id', $template->id)->first();

        $layers = [];
        for ($i = 1; $i <= ValidLayers::MAX_LAYERS + 1; $i++) {
            $layers[] = ['key' => "layer_{$i}", 'type' => 'text', 'properties' => []];
        }

        $response = $this->callMcpTool($token, 'put-editor-document', [
            'template_id' => $template->uuid,
            'revision' => 3,
            'document' => ['version' => 2, 'layers' => $layers],
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertStringContainsString(
            'A template can hold at most 200 layers; this one has 201.',
            $response->json('result.content.0.text')
        );

        $after = DB::table('templates')->where('id', $template->id)->first();

        $this->assertSame($before->editor_document, $after->editor_document);
        $this->assertSame($before->editor_revision, $after->editor_revision);
        $this->assertSame($before->layers, $after->layers);
        $this->assertSame($before->groups, $after->groups);
    }
}

<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

class PreflightEditorDocumentToolTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    public function test_it_inspects_the_current_draft_without_mutating_it_over_mcp(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'editor_revision' => 7, 'editor_document' => [
            'version' => 2, 'layers' => [['key' => 'image', 'type' => 'image', 'properties' => []]],
        ]]);
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        $response = $this->callTool($token, $template->uuid);

        $payload = $this->mcpToolPayload($response);
        $this->assertSame(7, $payload['revision']);
        $this->assertSame('image_asset_missing', $payload['issues'][0]['code']);
        $template->refresh();
        $this->assertSame(7, $template->editor_revision);
    }

    public function test_it_allows_a_company_peer_and_rejects_a_missing_document(): void
    {
        [, $template, $token] = $this->templateWithDocument(null);
        $missing = $this->callTool($token, $template->uuid);
        $missing->assertOk();
        $this->assertSame('editor_operation_document_missing', $this->mcpToolError($missing)['code']);

        $peer = User::factory()->create(['company_id' => $template->company_id]);
        $peerToken = $peer->createToken('peer', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;
        $template->forceFill(['editor_document' => ['version' => 2, 'layers' => []]])->save();
        $peerResponse = $this->callTool($peerToken, $template->uuid);
        $peerResponse->assertOk();
        $this->assertSame([], $this->mcpToolPayload($peerResponse)['issues']);
    }

    private function templateWithDocument(?array $document): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create(['company_id' => $company->id, 'user_id' => $user->id, 'editor_document' => $document]);

        return [$user, $template, $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken];
    }

    private function callTool(string $token, string $templateId)
    {
        return $this->callMcpTool($token, 'preflight-editor-document', ['template_id' => $templateId]);
    }
}

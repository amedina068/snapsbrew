<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * The four editor document tools share one limit. Each tool answers a full
 * limit with the same tool error, so an agent reads `isError` and the fix.
 */
class EditorDocumentRateLimitToolTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    public function test_each_editor_document_tool_answers_a_full_limit_with_a_tool_error(): void
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'editor_document' => ['version' => 2, 'layers' => []],
            'editor_revision' => 0,
            'editor_schema_version' => 2,
        ]);
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('editor-document:mcp:subject:'.$user->id, 60);
        }

        $calls = [
            'get-editor-document' => ['template_id' => $template->uuid],
            'put-editor-document' => ['template_id' => $template->uuid, 'revision' => 0, 'document' => ['version' => 2, 'layers' => []]],
            'apply-editor-operations' => ['template_id' => $template->uuid, 'revision' => 0, 'operations' => [['operation' => 'set_layer_type', 'layer_key' => 'hero', 'type' => 'text']]],
            'preflight-editor-document' => ['template_id' => $template->uuid],
        ];

        foreach ($calls as $tool => $arguments) {
            $response = $this->callMcpTool($token, $tool, $arguments);

            $response->assertOk();
            $this->assertTrue($response->json('result.isError'), "{$tool} did not answer with an error.");
            $error = $this->mcpToolError($response);
            $this->assertSame('editor_document_rate_limited', $error['code'], $tool);
            $this->assertNotEmpty($error['fix'], $tool);
        }
    }
}

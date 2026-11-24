<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

class ApplyEditorOperationsToolTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    public function test_it_applies_direct_renderer_operations_over_the_real_mcp_transport(): void
    {
        [$user, $template, $token] = $this->templateWithDocument();

        $response = $this->callTool($token, [
            'template_id' => $template->uuid,
            'revision' => 0,
            'operations' => [
                ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFit', 'value' => 'cover'],
                ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageMask', 'value' => 'rounded'],
                ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFocalX', 'value' => 23],
                ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFocalY', 'value' => 77],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'fontWeight', 'value' => 700],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'fontStyle', 'value' => 'italic'],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'letterSpacing', 'value' => 2],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'lineHeight', 'value' => 34],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'effects', 'value' => ['blur' => 2, 'stroke' => ['width' => 1, 'style' => 'solid', 'color' => '#000000']]],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'shadow', 'value' => ['x' => 1, 'y' => 2, 'blur' => 3, 'spread' => 0, 'color' => '#000000']],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'filterBlur', 'value' => 1],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'borderColor', 'value' => '#ffffff'],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'rotate', 'value' => 15],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'transformOrigin', 'value' => 'top left'],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'clipContent', 'value' => true],
                ['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'zIndex', 'value' => 3],
                ['operation' => 'set_layer_property', 'layer_key' => 'mark', 'property' => 'inlineSvg', 'value' => '<svg viewBox="0 0 1 1"><path d="M0 0h1v1H0z"/></svg>'],
                ['operation' => 'set_layer_type', 'layer_key' => 'mark', 'type' => 'shape'],
            ],
        ]);

        $response->assertOk();
        $payload = $this->mcpToolPayload($response);
        $this->assertSame(1, $payload['revision']);
        $this->assertSame('shape', $payload['document']['layers'][2]['type']);

        $template->refresh();
        $layers = json_decode($template->layers, true, 512, JSON_THROW_ON_ERROR);
        $properties = collect($layers)->keyBy('key')->map(fn (array $layer) => collect($layer['properties'])->pluck('value', 'name')->all());
        $this->assertSame('rounded', $properties['hero']['imageMask']);
        $this->assertSame(77, $properties['hero']['imageFocalY']);
        $this->assertSame(700, $properties['headline']['fontWeight']);
        $this->assertSame(2, $properties['headline']['effects']['blur']);
        $this->assertSame(true, $properties['headline']['clipContent']);
        $this->assertStringContainsString('<svg', $properties['mark']['inlineSvg']);
    }

    public function test_it_rejects_invalid_or_injected_operations_without_mutating_the_document(): void
    {
        [, $template, $token] = $this->templateWithDocument();
        $original = $template->editor_document;

        $cases = [
            [['operation' => 'set_layer_property', 'layer_key' => 'missing', 'property' => 'imageFit', 'value' => 'cover'], 'editor_operation_layer_not_found'],
            [['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'notReal', 'value' => 'x'], 'editor_operation_invalid_property'],
            [['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFocalX', 'value' => 101], 'editor_operation_invalid_value'],
            [['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFit', 'value' => 'stretch'], 'editor_operation_invalid_value'],
            [['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'effects', 'value' => ['url' => 'x']], 'editor_operation_invalid_value'],
            [['operation' => 'set_layer_property', 'layer_key' => 'headline', 'property' => 'shadow', 'value' => []], 'editor_operation_invalid_value'],
            [['operation' => 'set_layer_property', 'layer_key' => 'mark', 'property' => 'inlineSvg', 'value' => '<svg><script>alert(1)</script></svg>'], 'editor_operation_invalid_svg'],
            [['operation' => 'set_layer_property', 'layer_key' => 'mark', 'property' => 'inlineSvg', 'value' => '<svg><rect fill="u&#114;l(http://127.0.0.1/private.png)"/></svg>'], 'editor_operation_invalid_svg'],
            [['operation' => 'set_layer_property', 'layer_key' => 'mark', 'property' => 'inlineSvg', 'value' => '<svg>'.str_repeat(' ', 16000).'</svg>'], 'editor_operation_invalid_svg'],
            [['operation' => 'set_layer_type', 'layer_key' => 'mark', 'type' => 'video'], 'editor_operation_invalid_layer_type'],
            [['operation' => 'set_layer_type', 'layer_key' => 'mark', 'type' => 'image'], 'editor_operation_invalid_layer_type'],
            [['operation' => 'set_layer_type', 'layer_key' => 'mark', 'type' => 'shape'], 'editor_operation_shape_requires_svg'],
        ];
        foreach ($cases as [$operation, $code]) {
            $response = $this->callTool($token, ['template_id' => $template->uuid, 'revision' => 0, 'operations' => [$operation]]);
            $response->assertOk();
            $this->assertTrue($response->json('result.isError'));
            $error = $this->mcpToolError($response);
            $this->assertSame($code, $error['code']);
            $this->assertIsString($error['message']);
            $this->assertIsString($error['fix']);
            $this->assertFalse($error['retryable']);
            $template->refresh();
            $this->assertSame(0, $template->editor_revision);
            $this->assertSame($original, $template->editor_document);
        }
    }

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
        [, $template, $token] = $this->templateWithDocument();
        $template->forceFill([
            'editor_revision' => 1,
            // Both columns hold a value the projection would replace. With a
            // null on each side an overwritten row still reads as unchanged.
            'layers' => json_encode([['key' => 'hero', 'properties' => []]]),
            'groups' => json_encode([['id' => 'g1', 'name' => 'Header']]),
        ])->save();

        // Read the stored row, not the model, so no cast hides a changed value.
        $before = DB::table('templates')->where('id', $template->id)->first();

        $response = $this->callTool($token, [
            'template_id' => $template->uuid,
            'revision' => 0,
            'operations' => [['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFit', 'value' => 'cover']],
        ]);

        $response->assertOk();
        $error = $this->mcpToolError($response);
        $this->assertSame('editor_document_conflict', $error['code']);
        $this->assertSame(1, $error['details']['current_revision']);

        $after = DB::table('templates')->where('id', $template->id)->first();

        $this->assertSame($before->editor_document, $after->editor_document);
        $this->assertSame($before->editor_revision, $after->editor_revision);
        $this->assertSame($before->layers, $after->layers);
        $this->assertSame($before->groups, $after->groups);
    }

    public function test_it_bounds_operations_and_translates_document_validation_errors(): void
    {
        [, $template, $token] = $this->templateWithDocument();
        $operation = ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFit', 'value' => 'cover'];
        $bounded = $this->callTool($token, ['template_id' => $template->uuid, 'revision' => 0, 'operations' => array_fill(0, 101, $operation)]);
        $bounded->assertOk();
        $this->assertTrue($bounded->json('result.isError'));
        $this->assertSame('editor_operation_invalid_request', $this->mcpToolError($bounded)['code']);

        $template->forceFill(['editor_document' => ['version' => 2, 'layers' => $template->editor_document['layers'], 'groups' => ['bad' => true]]])->save();
        $response = $this->callTool($token, ['template_id' => $template->uuid, 'revision' => 0, 'operations' => [$operation]]);
        $response->assertOk();
        $this->assertTrue($response->json('result.isError'));
        $this->assertSame('editor_operation_document_invalid', $this->mcpToolError($response)['code']);
    }

    public function test_it_allows_a_company_peer_to_apply_an_operation(): void
    {
        [, $template] = $this->templateWithDocument();
        $peer = User::factory()->create(['company_id' => $template->company_id]);
        $token = $peer->createToken('peer', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        $response = $this->callTool($token, ['template_id' => $template->uuid, 'revision' => 0, 'operations' => [
            ['operation' => 'set_layer_property', 'layer_key' => 'hero', 'property' => 'imageFit', 'value' => 'cover'],
        ]]);

        $response->assertOk();
        $this->assertSame(1, $this->mcpToolPayload($response)['revision']);
    }

    private function templateWithDocument(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $document = ['version' => 2, 'layers' => [
            ['key' => 'hero', 'type' => 'image', 'properties' => []],
            ['key' => 'headline', 'type' => 'text', 'properties' => []],
            ['key' => 'mark', 'properties' => []],
        ]];
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $company->id, 'editor_document' => $document, 'editor_revision' => 0]);
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        return [$user, $template, $token];
    }

    private function callTool(string $token, array $arguments)
    {
        return $this->callMcpTool($token, 'apply-editor-operations', $arguments);
    }
}

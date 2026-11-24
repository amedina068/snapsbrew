<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

class DescribeEditorDocumentToolTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    public function test_it_returns_the_versioned_v2_contract_for_every_editor_domain()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        $response = $this->callMcpTool($token, 'describe-editor-document');

        $catalog = $this->mcpToolPayload($response);

        $this->assertSame(2, $catalog['contract_version']);
        $this->assertSame(2, $catalog['editor_document_version']);
        $this->assertSame([
            'auto_crop_masks',
            'focal_point_crop',
            'typography',
            'effects_stroke_blur_borders',
            'transforms_clipping_stacking',
            'sanitized_svg_shapes',
            'components_groups',
            'brand_kits_tokens',
            'responsive_families',
            'preflight',
        ], array_keys($catalog['domains']));

        foreach ($catalog['domains'] as $name => $domain) {
            $direct = in_array($name, ['auto_crop_masks', 'focal_point_crop', 'typography', 'effects_stroke_blur_borders', 'transforms_clipping_stacking', 'sanitized_svg_shapes'], true);
            $this->assertNotEmpty($domain['operation_families']);
            $this->assertNotEmpty($domain['capability_limits']);

            foreach ($domain['operation_families'] as $operation) {
                $this->assertSame(
                    $name === 'preflight' ? 'mcp_preflight_editor_document' : ($direct ? 'mcp_apply_editor_operations' : 'planned'),
                    $operation['availability']
                );
                $this->assertSame(
                    $name === 'preflight' ? 'known_invariants_only' : ($direct ? 'contract_validated' : 'not_yet_enforced'),
                    $operation['enforcement']
                );
                $this->assertArrayNotHasKey('support', $operation);
            }
        }
        $this->assertSame([
            'auto_crop_masks' => 'renderer_wired_for_direct_layers',
            'focal_point_crop' => 'renderer_wired_for_direct_layers',
            'typography' => 'renderer_wired_for_direct_layers',
            'effects_stroke_blur_borders' => 'renderer_wired_for_direct_layers',
            'transforms_clipping_stacking' => 'renderer_wired_for_direct_layers',
            'sanitized_svg_shapes' => 'renderer_wired_for_direct_layers',
            'components_groups' => 'groups_renderer_wired_components_later',
            'brand_kits_tokens' => 'later_operation_token_resolution',
            'responsive_families' => 'later_operation_canvas_selection',
            'preflight' => 'mcp_read_only',
        ], array_combine(
            array_keys($catalog['domains']),
            array_column($catalog['domains'], 'renderer_availability')
        ));

        $this->assertSame([
            'layers[].properties[name=imageFit].value',
            'layers[].properties[name=imagePositionX].value',
            'layers[].properties[name=imagePositionY].value',
            'layers[].properties[name=imageMask].value',
        ], $catalog['domains']['auto_crop_masks']['document_paths']);
        $this->assertSame([
            'layers[].properties[name=imageFocalX].value',
            'layers[].properties[name=imageFocalY].value',
        ], $catalog['domains']['focal_point_crop']['document_paths']);
        $this->assertSame([
            'layers[].properties[name=fontFamily].value',
            'layers[].properties[name=fontWeight].value',
            'layers[].properties[name=fontStyle].value',
            'layers[].properties[name=letterSpacing].value',
            'layers[].properties[name=lineHeight].value',
        ], $catalog['domains']['typography']['document_paths']);
        $this->assertSame([
            'layers[].properties[name=effects].value',
            'layers[].properties[name=shadow].value',
            'layers[].properties[name=filterBlur].value',
            'layers[].properties[name=borderStyle].value',
            'layers[].properties[name=borderWidth].value',
            'layers[].properties[name=borderColor].value',
        ], $catalog['domains']['effects_stroke_blur_borders']['document_paths']);
        $this->assertSame([
            'layers[].properties[name=rotate].value',
            'layers[].properties[name=transformOrigin].value',
            'layers[].properties[name=clipContent].value',
            'layers[].properties[name=zIndex].value',
        ], $catalog['domains']['transforms_clipping_stacking']['document_paths']);
        $this->assertSame([
            'layers[].type',
            'layers[].properties[name=inlineSvg].value',
        ], $catalog['domains']['sanitized_svg_shapes']['document_paths']);
        $this->assertSame([
            'groups[]',
            'layers[].groupId',
            'components[]',
            'components[].layers[]',
            'layers[].component_id',
            'layers[].overrides',
        ], $catalog['domains']['components_groups']['document_paths']);
        $this->assertSame([], $catalog['domains']['brand_kits_tokens']['document_paths']);
        $this->assertSame([
            'brand_tokens[].uuid',
            'brand_tokens[].kind',
            'brand_tokens[].value',
        ], $catalog['domains']['brand_kits_tokens']['storage_paths']);

        $this->assertSame([
            'read_contract',
            'read_document_and_revision',
            'compare_and_swap_direct_edit',
            'compare_and_swap_full_replacement',
            'inspect_draft_known_invariants',
            'publish_draft_for_immutable_rendering',
        ], array_column($catalog['safe_workflow'], 'operation'));
        $this->assertSame('mcp_read_only', $catalog['safe_workflow'][0]['availability']);
        $this->assertSame('mcp_read_only', $catalog['safe_workflow'][1]['availability']);
        $this->assertSame('apply-editor-operations', $catalog['safe_workflow'][2]['tool']);
        $this->assertSame('mcp_compare_and_swap_write', $catalog['safe_workflow'][2]['availability']);
        $this->assertSame('projects_direct_layers_and_groups_to_legacy_renderer_payload', $catalog['safe_workflow'][2]['render_effect']);
        $this->assertSame('put-editor-document', $catalog['safe_workflow'][3]['tool']);
        $this->assertSame('mcp_compare_and_swap_write', $catalog['safe_workflow'][3]['availability']);
        $this->assertSame('preflight-editor-document', $catalog['safe_workflow'][4]['tool']);
        $this->assertSame('mcp_read_only', $catalog['safe_workflow'][4]['availability']);
        $this->assertSame('publish-template', $catalog['safe_workflow'][5]['tool']);
        $this->assertStringContainsString('draft', $catalog['safe_workflow'][5]['purpose']);

        $propertyNames = [];
        foreach ($catalog['domains'] as $domain) {
            foreach ($domain['document_paths'] as $path) {
                if (preg_match('/properties\[name=([^\]]+)\]/', $path, $matches)) {
                    $propertyNames[] = $matches[1];
                }
            }
        }
        sort($propertyNames);
        $constraintNames = array_keys($catalog['property_constraints']);
        sort($constraintNames);
        $this->assertSame($propertyNames, $constraintNames);
        $this->assertSame(['type' => 'string', 'enum' => ['cover', 'contain', 'fill']], $catalog['property_constraints']['imageFit']);
        $this->assertSame(['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'unit' => 'percent'], $catalog['property_constraints']['imageFocalX']);
        $this->assertSame(['type' => 'integer', 'minimum' => 100, 'maximum' => 900], $catalog['property_constraints']['fontWeight']);
        $this->assertSame(['type' => 'string'], $catalog['property_constraints']['fontFamily']);
        $this->assertSame(['type' => 'number', 'unit' => 'px'], $catalog['property_constraints']['lineHeight']);
        $this->assertSame(262144, $catalog['persistence']['constraints']['maximum_bytes']);
        $this->assertSame('apply-editor-operations', $catalog['persistence']['operation_tool']['name']);
        $this->assertSame(100, $catalog['persistence']['operation_tool']['maximum_operations']);
        $this->assertSame(16000, $catalog['property_constraints']['inlineSvg']['maximum_bytes']);
    }
}

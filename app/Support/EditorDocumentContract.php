<?php

namespace App\Support;

final class EditorDocumentContract
{
    public const VERSION = 2;

    public const EDITOR_DOCUMENT_VERSION = 2;

    /** @return array<string, mixed> */
    public static function catalog(): array
    {
        return [
            'contract_version' => self::VERSION,
            'editor_document_version' => self::EDITOR_DOCUMENT_VERSION,
            'resource' => 'template.editor_document',
            'property_constraints' => self::propertyConstraints(),
            'persistence' => [
                'read_tool' => 'get-editor-document',
                'write_tool' => 'put-editor-document',
                'operation_tool' => ['name' => 'apply-editor-operations', 'mode' => 'compare_and_swap', 'maximum_operations' => 100,
                    'grammar' => ['set_layer_property' => '{operation:"set_layer_property",layer_key,property,value}', 'set_layer_type' => '{operation:"set_layer_type",layer_key,type}'],
                    'conflict' => 'editor_document_conflict returns current_revision and current_document; reread and never overwrite automatically.'],
                'write_mode' => 'whole_document_compare_and_swap',
                'revision_field' => 'revision',
                'constraints' => ['maximum_bytes' => 262144, 'maximum_nodes' => 1000, 'maximum_depth' => 12, 'maximum_string_length' => 16384],
                'limits' => [
                    'Only bounded JSON is accepted (262144 bytes, 1000 nodes, depth 12).',
                    'The document is company-scoped; callers cannot read or write another company document.',
                ],
            ],
            'safe_workflow' => [
                [
                    'step' => 1,
                    'tool' => 'describe-editor-document',
                    'operation' => 'read_contract',
                    'availability' => 'mcp_read_only',
                    'purpose' => 'Select only supported operation families and honor their limits.',
                ],
                [
                    'step' => 2,
                    'tool' => 'get-editor-document',
                    'operation' => 'read_document_and_revision',
                    'availability' => 'mcp_read_only',
                    'purpose' => 'Read the current document before proposing a replacement.',
                ],
                [
                    'step' => 3,
                    'tool' => 'apply-editor-operations',
                    'operation' => 'compare_and_swap_direct_edit',
                    'availability' => 'mcp_compare_and_swap_write',
                    'render_effect' => 'projects_direct_layers_and_groups_to_legacy_renderer_payload',
                    'purpose' => 'Use the closed operation grammar for supported direct-layer changes; reread and never overwrite automatically on a conflict.',
                ],
                [
                    'step' => 4,
                    'tool' => 'put-editor-document',
                    'operation' => 'compare_and_swap_full_replacement',
                    'availability' => 'mcp_compare_and_swap_write',
                    'render_effect' => 'projects_direct_layers_and_groups_to_legacy_renderer_payload',
                    'purpose' => 'Advanced alternative to step 3 only: replace the complete v2 document with the unchanged revision, then reread on conflict.',
                ],
                [
                    'step' => 5,
                    'tool' => 'preflight-editor-document',
                    'operation' => 'inspect_draft_known_invariants',
                    'availability' => 'mcp_read_only',
                    'purpose' => 'Inspect known draft invariants before publishing; no issues is not a visual-quality or completed-render guarantee.',
                ],
                [
                    'step' => 6,
                    'tool' => 'publish-template',
                    'operation' => 'publish_draft_for_immutable_rendering',
                    'availability' => 'mcp_destructive_write',
                    'purpose' => 'A successful put-editor-document updates only the draft. When a template already has a published version, create-snap and preview tools keep that published snapshot until publish-template is called.',
                ],
            ],
            'domains' => [
                'auto_crop_masks' => self::domain(
                    [
                        'layers[].properties[name=imageFit].value',
                        'layers[].properties[name=imagePositionX].value',
                        'layers[].properties[name=imagePositionY].value',
                        'layers[].properties[name=imageMask].value',
                    ],
                    [self::operation('set_image_fit', 'cover | contain | fill'), self::operation('set_mask', 'none | circle | rounded')],
                    ['Automatic subject detection and raster crop baking are not available.', 'Mask support is CSS presentation only.'],
                    'renderer_wired_for_direct_layers'
                ),
                'focal_point_crop' => self::domain(
                    ['layers[].properties[name=imageFocalX].value', 'layers[].properties[name=imageFocalY].value'],
                    [self::operation('set_focal_point', 'two percentages in the inclusive 0..100 range')],
                    ['Focal points affect background positioning only; no AI focal-point extraction is available.'],
                    'renderer_wired_for_direct_layers'
                ),
                'typography' => self::domain(
                    [
                        'layers[].properties[name=fontFamily].value',
                        'layers[].properties[name=fontWeight].value',
                        'layers[].properties[name=fontStyle].value',
                        'layers[].properties[name=letterSpacing].value',
                        'layers[].properties[name=lineHeight].value',
                    ],
                    [self::operation('set_font_family'), self::operation('set_weight_style_tracking_and_line_height')],
                    ['Font availability and text shaping depend on the renderer font inventory.', 'No MCP operation presently resolves or uploads font files.'],
                    'renderer_wired_for_direct_layers'
                ),
                'effects_stroke_blur_borders' => self::domain(
                    [
                        'layers[].properties[name=effects].value',
                        'layers[].properties[name=shadow].value',
                        'layers[].properties[name=filterBlur].value',
                        'layers[].properties[name=borderStyle].value',
                        'layers[].properties[name=borderWidth].value',
                        'layers[].properties[name=borderColor].value',
                    ],
                    [self::operation('set_shadow_stroke_blur_and_border')],
                    ['Effects are renderer CSS values; unsupported browser/CSS combinations can degrade.', 'No pixel-perfect cross-renderer equivalence is guaranteed.'],
                    'renderer_wired_for_direct_layers'
                ),
                'transforms_clipping_stacking' => self::domain(
                    [
                        'layers[].properties[name=rotate].value',
                        'layers[].properties[name=transformOrigin].value',
                        'layers[].properties[name=clipContent].value',
                        'layers[].properties[name=zIndex].value',
                    ],
                    [self::operation('set_rotation_and_origin'), self::operation('set_clipping'), self::operation('set_stacking_order')],
                    ['Stack order is relative to sibling layers only.', 'Perspective, arbitrary transform matrices, and blend isolation are not in this contract.'],
                    'renderer_wired_for_direct_layers'
                ),
                'sanitized_svg_shapes' => self::domain(
                    ['layers[].type', 'layers[].properties[name=inlineSvg].value'],
                    [self::operation('set_inline_svg_shape', 'type must be shape; SVG is fail-closed sanitized')],
                    ['Only an allow list of SVG shape, text, group and gradient elements and their geometry and paint attributes passes. Scripts, event or style attributes, CSS url() values, hrefs, images, animation, foreignObject, HTML elements, comments, DTDs, and entities are rejected.', 'The sanitizer is not an SVG feature-completion service.'],
                    'renderer_wired_for_direct_layers'
                ),
                'components_groups' => self::domain(
                    ['groups[]', 'layers[].groupId', 'components[]', 'components[].layers[]', 'layers[].component_id', 'layers[].overrides'],
                    [self::operation('define_local_component'), self::operation('instantiate_local_component'), self::operation('apply_instance_overrides')],
                    ['Direct groups are projected to the legacy renderer payload. Component expansion is a later operation and is not wired into the renderer.', 'Recursive component expansion is not supported.'],
                    'groups_renderer_wired_components_later'
                ),
                'brand_kits_tokens' => self::domain(
                    [],
                    [self::operation('reference_company_brand_token', 'token ids must belong to the document company')],
                    ['Token CRUD and token resolution are not yet MCP tools.', 'No editor-document token-reference property is currently consumed by the renderer.'],
                    'later_operation_token_resolution',
                    ['brand_tokens[].uuid', 'brand_tokens[].kind', 'brand_tokens[].value']
                ),
                'responsive_families' => self::domain(
                    ['default_canvas', 'canvases.default', 'canvases.{name}'],
                    [self::operation('define_canvas_family'), self::operation('select_canvas', 'canvas inherits from default')],
                    ['A missing canvas fails resolution.', 'No MCP tool currently renders every family member or calculates breakpoint interpolation.'],
                    'later_operation_canvas_selection'
                ),
                'preflight' => self::domain(
                    ['layers[]', 'layers[].source.asset_id'],
                    [self::preflightOperation()],
                    ['Preflight validates only known document invariants, not visual taste or a finished rendered bitmap.'],
                    'mcp_read_only'
                ),
            ],
        ];
    }

    /** @param array<int, string> $paths @param array<int, array<string, mixed>> $operations @param array<int, string> $limits @param array<int, string> $storagePaths @return array<string, mixed> */
    private static function domain(array $paths, array $operations, array $limits, string $rendererAvailability, array $storagePaths = []): array
    {
        return [
            'document_paths' => $paths,
            'storage_paths' => $storagePaths,
            'renderer_availability' => $rendererAvailability,
            'operation_families' => $operations,
            'capability_limits' => $limits,
        ];
    }

    /** @return array<string, mixed> */
    private static function operation(string $name, ?string $input = null): array
    {
        $available = in_array($name, ['set_image_fit', 'set_mask', 'set_focal_point', 'set_font_family', 'set_weight_style_tracking_and_line_height', 'set_shadow_stroke_blur_and_border', 'set_rotation_and_origin', 'set_clipping', 'set_stacking_order', 'set_inline_svg_shape'], true);

        return array_filter([
            'name' => $name,
            'availability' => $available ? 'mcp_apply_editor_operations' : 'planned',
            'enforcement' => $available ? 'contract_validated' : 'not_yet_enforced',
            'input' => $input,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private static function preflightOperation(): array
    {
        return [
            'name' => 'inspect_document',
            'availability' => 'mcp_preflight_editor_document',
            'enforcement' => 'known_invariants_only',
            'input' => 'returns structured code, path, and message from the current draft',
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function propertyConstraints(): array
    {
        return [
            'imageFit' => ['type' => 'string', 'enum' => ['cover', 'contain', 'fill']], 'imagePositionX' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'unit' => 'percent'],
            'imagePositionY' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'unit' => 'percent'], 'imageMask' => ['type' => 'string', 'enum' => ['none', 'circle', 'rounded']],
            'imageFocalX' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'unit' => 'percent'], 'imageFocalY' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'unit' => 'percent'],
            'fontFamily' => ['type' => 'string'], 'fontWeight' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 900],
            'fontStyle' => ['type' => 'string', 'enum' => ['normal', 'italic']], 'letterSpacing' => ['type' => 'number', 'minimum' => -20, 'maximum' => 100, 'unit' => 'px'],
            'lineHeight' => ['type' => 'number', 'unit' => 'px'], 'effects' => ['type' => 'object', 'properties' => ['blur' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'unit' => 'px'], 'stroke' => ['type' => 'object', 'properties' => ['width' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100, 'unit' => 'px'], 'style' => ['type' => 'string', 'enum' => ['solid', 'dashed', 'dotted']], 'color' => ['type' => 'string', 'format' => 'css_color']]]]],
            'shadow' => ['type' => 'object', 'required' => ['x', 'y', 'blur', 'spread', 'color'], 'properties' => ['x' => ['type' => 'number', 'unit' => 'px'], 'y' => ['type' => 'number', 'unit' => 'px'], 'blur' => ['type' => 'number', 'unit' => 'px'], 'spread' => ['type' => 'number', 'unit' => 'px'], 'color' => ['type' => 'string', 'format' => 'css_color']]], 'filterBlur' => ['type' => 'number', 'unit' => 'px'],
            'borderStyle' => ['type' => 'string'], 'borderWidth' => ['type' => 'number', 'unit' => 'px'],
            'borderColor' => ['type' => 'string', 'format' => 'css_color'], 'rotate' => ['type' => 'number', 'unit' => 'degrees'],
            'transformOrigin' => ['type' => 'string', 'enum' => ['center', 'top left', 'top right', 'bottom left', 'bottom right']], 'clipContent' => ['type' => 'boolean'],
            'zIndex' => ['type' => 'number'], 'inlineSvg' => ['type' => 'string', 'maximum_bytes' => 16000],
        ];
    }
}

<?php

namespace App\Support;

use App\Models\Template;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;

/**
 * One canvas contract for every MCP tool that takes a canvas: a `size` preset
 * OR an explicit `width` and `height`. `create-template`, `preview-design` and
 * `create-snap-from-design` share these rules, these messages and this
 * resolution, so a preset means the same thing on each.
 */
final class CanvasRules
{
    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'size' => ['nullable', 'string', Rule::in(TemplateSizes::values(withCustom: false))],
            'width' => ['required_without:size', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'height' => ['required_without:size', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'size.in' => 'Unknown size preset. Call list-template-sizes for the presets you can use, or pass width and height instead.',
            'width.required_without' => 'Pass a `size` preset or an explicit `width` and `height`.',
            'height.required_without' => 'Pass a `size` preset or an explicit `width` and `height`.',
        ];
    }

    /**
     * The canvas fragment of a tool's input schema. The bound is read from the
     * one constant the rules read, so the text an agent sees cannot drift from
     * the value the validator enforces.
     *
     * @return array<string, mixed>
     */
    public static function schema(JsonSchema $schema): array
    {
        $max = Template::MAX_DIMENSION;

        return [
            'size' => $schema->string()->description('Canvas size preset, for example "instagram_story" or "open_graph". Sets width and height for you. Call list-template-sizes for every preset.'),
            'width' => $schema->integer()->description("Canvas width in px (1–{$max}). Required only when you pass no `size`."),
            'height' => $schema->integer()->description("Canvas height in px (1–{$max}). Required only when you pass no `size`."),
        ];
    }

    /**
     * The canvas the validated input names. A preset wins over dimensions sent
     * beside it, because only the preset says which of several same-shaped
     * presets the caller meant.
     *
     * @param  array<string, mixed>  $validated
     * @return array{width: int, height: int, size: string}
     */
    public static function resolve(array $validated): array
    {
        $preset = TemplateSizes::find($validated['size'] ?? null);

        if ($preset) {
            return ['width' => $preset['width'], 'height' => $preset['height'], 'size' => $preset['value']];
        }

        $width = (int) ($validated['width'] ?? 0);
        $height = (int) ($validated['height'] ?? 0);

        return ['width' => $width, 'height' => $height, 'size' => TemplateSizes::resolveValue($width, $height)];
    }
}

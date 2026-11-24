<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Template;
use App\Parsers\TemplateLayerParser;
use App\Rules\SafeLayers;
use App\Rules\ValidLayers;
use App\Services\TemplateVersioning;
use App\Support\TemplateSizes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'cursor' => ['string', 'sometimes'],
        ]);

        $templates = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->select('uuid', 'title', 'width', 'height', 'layers', 'created_at')
            ->cursorPaginate();

        foreach ($templates->items() as $template) {
            $template->setAttribute(
                'layers',
                (new TemplateLayerParser(json_decode($template->layers, true)))->parse()
            );
            // `templates.width` and `templates.height` are string columns with
            // no model cast. The cast here makes the list, the read and the
            // create answer one type for one field.
            $template->setAttribute('width', (int) $template->width);
            $template->setAttribute('height', (int) $template->height);
        }

        return ApiResponse::page($templates->items(), [
            'previous_cursor' => optional($templates->previousCursor())->encode(),
            'next_cursor' => optional($templates->nextCursor())->encode(),
        ]);
    }

    /**
     * The canvas size presets a template write accepts as `size`.
     */
    public function sizes()
    {
        return ApiResponse::data(TemplateSizes::catalog());
    }

    /**
     * Read one template, its parsed layers and its publish state.
     *
     * `published_version` and `has_unpublished_edits` mirror the MCP tool
     * `get-template-details`. `layers` is the draft, so a caller needs
     * `has_unpublished_edits` to know that the draft does not render yet.
     *
     * The select adds the three columns `hasUnpublishedEdits()` reads and the
     * response does not return: `groups`, `editor_document` and
     * `published_version_id`. The response names each field, so none of the
     * three reaches the body.
     *
     * `size` holds the same position it holds in `store()`, and `width` and
     * `height` carry the same integer type, so the two field lists read alike.
     */
    public function show($templateUuid)
    {
        $template = Template::query()
            ->where([
                'company_id' => Auth::user()->company_id,
                'uuid' => $templateUuid,
            ])
            ->select(
                'uuid',
                'title',
                'size',
                'width',
                'height',
                'layers',
                'groups',
                'editor_document',
                'published_version_id',
                'created_at'
            )
            ->firstOrFail();

        return ApiResponse::data([
            'uuid' => $template->uuid,
            'title' => $template->title,
            'size' => $template->size,
            'width' => (int) $template->width,
            'height' => (int) $template->height,
            'layers' => (new TemplateLayerParser(json_decode($template->layers, true)))->parse(),
            'published_version' => $template->publishedVersion?->version_number,
            'has_unpublished_edits' => $template->hasUnpublishedEdits(),
            'created_at' => $template->created_at,
        ]);
    }

    /**
     * Create a template and generate its preview image.
     *
     * The response names every field it returns. The route returned the
     * Eloquent model, and `App\Models\Template` declares neither `$hidden`
     * nor `$visible`, so every loaded attribute reached the caller. That body
     * carried `company_id`, `user_id`, the numeric `id`, the raw `layers` JSON
     * and the whole `media` relation. The field set here matches `show()`,
     * plus the `size` preset that the server resolves for the caller.
     *
     * The body is `{"data": ..., "message": ...}`.
     * `App\Http\Responses\ApiResponse` holds that rule for the whole API.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Template::class);

        $validated = $request->validate(self::storeRules(), self::storeMessages());

        $preset = TemplateSizes::find($validated['size'] ?? null);

        $template = Template::create(self::withSize($preset, [
            'user_id' => Auth::id(),
            'company_id' => Auth::user()->company_id,
            'title' => $validated['title'],
            'width' => $preset['width'] ?? $validated['width'],
            'height' => $preset['height'] ?? $validated['height'],
            // A create has no stored layers to fall back on, so an absent list
            // is an empty canvas. `json_encode(null)` writes the four-character
            // string `null`, which the render path cannot read.
            'layers' => json_encode($validated['layers'] ?? []),
            'groups' => isset($validated['groups']) ? json_encode($validated['groups']) : null,
        ]));

        $template->generatePreviewImage();

        return ApiResponse::data(self::summaryFields($template), 'Template created successfully');
    }

    /**
     * Update a template. Render a new preview only when the image changes.
     *
     * `Template::saveWithPreview()` owns the rule. A repeat of the same
     * `PATCH`, or a change to the title only, keeps the preview URL.
     *
     * The path segment accepts the UUID and the numeric id.
     * `Template::scopeIdentifier()` owns the branch that picks the column.
     *
     * The body returns the same six fields `store()` returns. A `PATCH` that
     * sends `size` changes `width` and `height`, and a `PATCH` that sends
     * `width` and `height` changes `size`, so the resolved canvas is state the
     * caller cannot compute. Before this, the body was `{"message": ...}` and
     * a caller had to read the template again to learn it. The `data` key is
     * new, so a client that reads `message` still works.
     */
    public function update(Request $request, $templateIdentifier)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->identifier($templateIdentifier)
            ->firstOrFail();

        $this->authorize('update', $template);

        if ($template->editor_schema_version !== null) {
            return response()->json([
                'code' => 'legacy_edit_rejected_for_v2',
                'message' => 'This template has a v2 editor document, so this route cannot change it. Read GET /v1/templates/{templateUuid}/document, then send PATCH or PUT /v1/templates/{templateUuid}/document with its revision.',
            ], 409);
        }

        $validated = $request->validate(self::writeRules());

        $preset = TemplateSizes::find($validated['size'] ?? null);

        $template->fill(self::withSize(
            $preset,
            self::partialAttributes($preset, $validated)
        ))->saveWithPreview();

        return ApiResponse::data(self::summaryFields($template), 'Template updated successfully');
    }

    /**
     * Publish the draft of a template as a new immutable version.
     *
     * The gate names `update`, because `TemplatePolicy::update` is
     * company-shared: every customer in the owning company can publish. A
     * caller from another company never reaches the gate. The fetch is scoped
     * by `company_id` and answers 404.
     *
     * The repeat guard lives in `TemplateVersioning::publish()`. A second call
     * on a clean draft returns the version which is already live and writes no
     * second row.
     *
     * The body carries `id` and `uuid` with the same value. `id` is
     * deprecated. `App\Mcp\Tools\PublishTemplateTool` answers both keys too,
     * so the two surfaces stay level.
     */
    public function publish(TemplateVersioning $versioning, $templateUuid)
    {
        $template = Template::where([
            'company_id' => Auth::user()->company_id,
            'uuid' => $templateUuid,
        ])->firstOrFail();

        $this->authorize('update', $template);

        $version = $versioning->publish($template);

        return ApiResponse::data([
            // `id` repeats `uuid` and is deprecated. Every other template
            // endpoint names the field `uuid`, and a client that reads `id`
            // still works. Keep both until a major version removes `id`.
            'id' => $template->uuid,
            'uuid' => $template->uuid,
            'published_version' => $version->version_number,
        ], 'Template published. New snaps and previews now render this version.');
    }

    /**
     * Delete a template.
     *
     * The path segment accepts the UUID and the numeric id.
     * `Template::scopeIdentifier()` owns the branch that picks the column.
     */
    public function destroy($templateIdentifier)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->identifier($templateIdentifier)
            ->firstOrFail();

        $this->authorize('delete', $template);

        $template->delete();

        // A delete is the one exemption from the `data` envelope. The row is
        // gone, so there is no resource to return, and a `data` key here would
        // describe a template that no longer exists. `App\Http\Responses\ApiResponse`
        // holds the rule and the reason.
        return ApiResponse::message('Template deleted successfully');
    }

    /**
     * The template fields a write returns.
     *
     * `store()` and `update()` answer the same six fields, so one method
     * builds them. `show()` keeps its own list, because it adds the parsed
     * layers and the two publish-state fields.
     *
     * @return array<string, mixed>
     */
    private static function summaryFields(Template $template): array
    {
        return [
            'uuid' => $template->uuid,
            'title' => $template->title,
            'size' => $template->size,
            'width' => (int) $template->width,
            'height' => (int) $template->height,
            'created_at' => $template->created_at,
        ];
    }

    /**
     * The attributes a partial update writes.
     *
     * Every field but `title` is nullable, so a `PATCH` body can hold any
     * subset of them. A field the caller did not send keeps its stored value,
     * so this array does not name it. The old array named every field and read
     * each one with `??`, which turned an absent field into a written null and
     * answered a legal body of only a title with a 500.
     *
     * A null is written only where the column accepts one. `templates.groups`
     * is nullable, so `groups: null` clears the groups. `templates.width`,
     * `templates.height` and `templates.layers` are NOT NULL, so a null there
     * keeps the stored value.
     *
     * The absent field and the explicit null are two different requests.
     * `array_key_exists` over the validated array tells them apart, because
     * `Validator::validated()` holds a key only when the request carried it.
     * `App\Mcp\Tools\EditTemplateTool` does the same job with `array_filter`,
     * and that answer does not fit here: `array_filter` drops every null, so an
     * explicit `groups: null` could never clear the groups. That tool has no
     * `groups` field, so the two requests never differ there.
     *
     * @param  array{name: string, value: string, width: int, height: int, platform: string}|null  $preset
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private static function partialAttributes(?array $preset, array $validated): array
    {
        $attributes = ['title' => $validated['title']];

        foreach (['width', 'height'] as $dimension) {
            if ($preset !== null) {
                $attributes[$dimension] = $preset[$dimension];
            } elseif (($validated[$dimension] ?? null) !== null) {
                $attributes[$dimension] = $validated[$dimension];
            }
        }

        if (($validated['layers'] ?? null) !== null) {
            $attributes['layers'] = json_encode($validated['layers']);
        }

        if (array_key_exists('groups', $validated)) {
            $attributes['groups'] = $validated['groups'] === null
                ? null
                : json_encode($validated['groups']);
        }

        return $attributes;
    }

    /**
     * Add `size` to a template write only when the caller named a preset.
     *
     * `Template::booted()` owns the resolve. A controller that resolves the
     * preset itself writes its own guess as the caller's choice, and the hook
     * reads a dirty `size` and returns. A template stored at `tiktok_vertical`
     * then came back `instagram_story` after a write of the same dimensions.
     * `App\Mcp\Tools\EditTemplateTool` drops a `size` the caller did not send
     * for the same reason.
     *
     * @param  array{name: string, value: string, width: int, height: int, platform: string}|null  $preset
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function withSize(?array $preset, array $attributes): array
    {
        if ($preset === null) {
            return $attributes;
        }

        return $attributes + ['size' => $preset['value']];
    }

    /**
     * Validation for a template create.
     *
     * A create has no stored value to fall back on, so the canvas is
     * required. A `size` preset supplies the dimensions, so
     * `required_without:size` accepts either input. `writeRules()` keeps the
     * dimensions nullable, which is correct for a `PATCH`: an absent field
     * there keeps the stored value.
     *
     * The `+` union keeps the two rules below and takes every other rule from
     * `writeRules()`. `App\Mcp\Tools\CreateTemplateTool` carries the same
     * two rules and the same two messages.
     *
     * @return array<string, mixed>
     */
    private static function storeRules(): array
    {
        return [
            'width' => ['required_without:size', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'height' => ['required_without:size', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
        ] + self::writeRules();
    }

    /**
     * Messages for the create rules that name a second field.
     *
     * The default text for `required_without` names the other field and does
     * not say that a preset is the other way to set the canvas.
     *
     * @return array<string, string>
     */
    private static function storeMessages(): array
    {
        return [
            'width.required_without' => 'Send a `size` preset or an explicit `width` and `height`.',
            'height.required_without' => 'Send a `size` preset or an explicit `width` and `height`.',
        ];
    }

    /**
     * Validation for template writes:
     *  - title: bounded string (was previously unvalidated raw input).
     *  - size: a preset from App\Support\TemplateSizes. It sets width/height,
     *    so a caller does not have to know the pixel dimensions of a Story.
     *  - width/height: bounded — an unbounded canvas fed to Browsershot
     *    allocates proportional render memory (cheap DoS once scriptable).
     *  - layers: ValidLayers enforces structure (unique string `key`,
     *    `properties` array of { name, value }) so a malformed layer is
     *    rejected with a 422 instead of saving clean and crashing at render
     *    time; SafeLayers blocks SSRF stored in a backgroundImage URL.
     */
    private static function writeRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'size' => ['nullable', 'string', Rule::in(TemplateSizes::values(withCustom: false))],
            'width' => ['nullable', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'height' => ['nullable', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'layers' => ['bail', 'nullable', 'array', new ValidLayers, new SafeLayers],
            // Lightweight group payload — editor construct over flat layers. Not
            // part of ValidLayers (which validates `layers`).
            'groups' => ['nullable', 'array'],
            'groups.*.id' => ['required', 'string', 'max:64'],
            'groups.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'groups.*.visible' => ['sometimes', 'boolean'],
            'groups.*.collapsed' => ['sometimes', 'boolean'],
            'groups.*.order' => ['sometimes', 'integer'],
        ];
    }
}

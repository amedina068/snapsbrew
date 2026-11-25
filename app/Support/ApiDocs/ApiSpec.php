<?php

namespace App\Support\ApiDocs;

use App\Models\Template;
use App\Support\ImagePurposeGuide;
use App\Support\PlatformGuide;
use App\Support\VariantProperties;

/**
 * Single source of truth for the Snapsbrew public API.
 *
 * Every documentation surface — the in-app docs screens, the OpenAPI schema,
 * the Postman collection and the per-screen Markdown — is generated from this
 * one structure. Edit the API here and nowhere else.
 *
 * Shape of an endpoint:
 *   id          string   stable slug, e.g. "snaps.create"
 *   method      string   HTTP verb
 *   path        string   path relative to the API base (no /api prefix)
 *   summary     string   one-line title
 *   description string   prose, may contain newlines
 *   auth        string   "bearer" | "none" | "signature"
 *   abilities   array    required Sanctum token abilities
 *   middleware  array    extra gates worth surfacing (e.g. "logApiRequest")
 *   parameters  array    path/query params: name, in, type, required, description, example
 *   body        array|null   request body: contentType + fields[]
 *   responses   array    status, description, example (assoc array or list)
 *   notes       array    extra caveats shown as callouts
 *   examples    array    code samples keyed by language label
 */
class ApiSpec
{
    public const VERSION = '1.0.0';

    public const WEBHOOK_SIGNATURE_NOTE = 'Each webhook carries three headers: `X-Snapsbrew-Delivery` (one ID for all attempts of a delivery), '
        .'`X-Snapsbrew-Timestamp` (Unix seconds) and `X-Snapsbrew-Signature`. The signature is the HMAC-SHA256 hex digest of '
        .'`{timestamp}.{raw body}` with the webhook signing secret of your company (**Account > Developers**). '
        .'Compare it in constant time, and reject a timestamp that is more than five minutes old.';

    /**
     * The spec is written with the hosted domain in its examples. Every public
     * accessor swaps it for the URL of this instance, so the docs, the OpenAPI
     * schema and the Postman collection name the server that serves them.
     */
    private const SOURCE_URL = 'https://snapsbrew.com';

    public static function info(): array
    {
        return self::localize([
            'title' => config('app.name').' API',
            'version' => self::VERSION,
            'description' => 'Generate images, PDFs and screenshots from reusable '
                .'templates. The Snapsbrew API turns design templates into a '
                .'render pipeline you can call from any stack.',
            'baseUrl' => self::SOURCE_URL.'/api',
            // The signed URL route is outside `/api`, so it needs the site root.
            'siteUrl' => self::SOURCE_URL,
            'contactEmail' => config('snapsbrew.support_email') ?: config('mail.from.address'),
            // The values come from `RouteServiceProvider::configureRateLimiting()`.
            'rateLimits' => 'Rate limits: every bearer endpoint is in the `api` group, which allows 60 requests '
                .'per minute for one user (or one IP address when the request has no user). The two preview '
                .'endpoints also allow 10 requests per minute for one user. The editor document endpoints also '
                .'allow 30 requests per minute for one user and 180 for one company. Over a limit, the API returns '
                .'429 with a `Retry-After` header. Each response carries `X-RateLimit-Limit` and '
                .'`X-RateLimit-Remaining`.',
        ]);
    }

    /**
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    private static function localize(mixed $value): mixed
    {
        $site = rtrim((string) config('app.url'), '/');

        if (is_string($value)) {
            return str_replace(self::SOURCE_URL, $site, $value);
        }

        if (is_array($value)) {
            return array_map(fn ($item) => self::localize($item), $value);
        }

        return $value;
    }

    /**
     * Reusable object schemas referenced by responses.
     */
    public static function schemas(): array
    {
        return self::localize(self::schemaDefinitions());
    }

    private static function schemaDefinitions(): array
    {
        return [
            'Variant' => [
                'description' => 'One override for one layer: the layer key and exactly one override property. '
                    .'GET /v1/guides/variant-properties lists each property with an example.',
                'fields' => array_merge(
                    [['name' => 'layer', 'type' => 'string', 'description' => 'Key of the layer to override. Required.']],
                    array_map(fn (array $property) => [
                        'name' => $property['name'],
                        'type' => $property['schema']['type'],
                        'description' => $property['description'],
                    ], self::variantProperties()),
                ),
                'openapi' => [
                    'oneOf' => array_map(fn (array $property) => [
                        'type' => 'object',
                        'required' => ['layer', $property['name']],
                        'additionalProperties' => false,
                        'properties' => [
                            'layer' => ['type' => 'string', 'description' => 'Key of the layer to override.'],
                            $property['name'] => $property['schema'] + ['description' => $property['description']],
                        ],
                    ], self::variantProperties()),
                ],
            ],
            'LayerDefinition' => [
                'description' => 'One layer in the `layers` array of a template write: a unique key and its design-time properties. '
                    .'GET /v1/guides/layers lists every property name and value shape.',
                'fields' => [
                    ['name' => 'key', 'type' => 'string', 'required' => true, 'description' => 'Unique layer key. Variants target this key.'],
                    ['name' => 'type', 'type' => 'string', 'description' => 'Editor layer type, for example text, image, qr_code, barcode or empty. Optional.'],
                    ['name' => 'properties', 'type' => 'LayerProperty[]', 'required' => true, 'description' => 'Design-time properties. Send [] for none.'],
                ],
            ],
            'LayerProperty' => [
                'description' => 'One design-time property of a layer.',
                'fields' => [
                    ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Property name, for example textContent or fontFamily.'],
                    ['name' => 'value', 'type' => 'any', 'schema' => ['description' => 'Any JSON value. The shape depends on the property.'], 'description' => 'Property value.'],
                ],
            ],
            'Template' => [
                'description' => 'One template, as GET /v1/templates/{templateUuid} returns it under `data`.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'uuid', 'type' => 'string', 'required' => true, 'description' => 'Stable public identifier.'],
                    ['name' => 'title', 'type' => 'string', 'required' => true, 'description' => 'Human-readable name.'],
                    ['name' => 'size', 'type' => 'string|null', 'required' => true, 'description' => 'Canvas size preset, or `custom`.'],
                    // `templates.width` and `templates.height` are string columns
                    // with no model cast. Every template endpoint casts the two
                    // values to integers.
                    ['name' => 'width', 'type' => 'integer', 'required' => true, 'description' => 'Canvas width in pixels.'],
                    ['name' => 'height', 'type' => 'integer', 'required' => true, 'description' => 'Canvas height in pixels.'],
                    ['name' => 'layers', 'type' => 'Layer[]', 'required' => true, 'description' => 'Addressable layers and their modifications.'],
                    ['name' => 'published_version', 'type' => 'integer|null', 'required' => true, 'description' => 'The version that renders. Null until the first publish.'],
                    ['name' => 'has_unpublished_edits', 'type' => 'boolean', 'required' => true, 'description' => 'True when the draft differs from the published version.'],
                    ['name' => 'created_at', 'type' => 'datetime', 'required' => true, 'description' => 'ISO-8601 creation timestamp.'],
                ],
            ],
            'TemplateListItem' => [
                'description' => 'One template in the list of GET /v1/templates.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'uuid', 'type' => 'string', 'required' => true, 'description' => 'Stable public identifier.'],
                    ['name' => 'title', 'type' => 'string', 'required' => true, 'description' => 'Human-readable name.'],
                    ['name' => 'width', 'type' => 'integer', 'required' => true, 'description' => 'Canvas width in pixels.'],
                    ['name' => 'height', 'type' => 'integer', 'required' => true, 'description' => 'Canvas height in pixels.'],
                    ['name' => 'layers', 'type' => 'Layer[]', 'required' => true, 'description' => 'Addressable layers and their modifications.'],
                    ['name' => 'created_at', 'type' => 'datetime', 'required' => true, 'description' => 'ISO-8601 creation timestamp.'],
                ],
            ],
            'TemplateSummary' => [
                'description' => 'The six fields that a template create or update returns under `data`.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'uuid', 'type' => 'string', 'required' => true, 'description' => 'Stable public identifier.'],
                    ['name' => 'title', 'type' => 'string', 'required' => true, 'description' => 'Human-readable name.'],
                    ['name' => 'size', 'type' => 'string|null', 'required' => true, 'description' => 'Canvas size preset, or `custom`.'],
                    ['name' => 'width', 'type' => 'integer', 'required' => true, 'description' => 'Canvas width in pixels.'],
                    ['name' => 'height', 'type' => 'integer', 'required' => true, 'description' => 'Canvas height in pixels.'],
                    ['name' => 'created_at', 'type' => 'datetime', 'required' => true, 'description' => 'ISO-8601 creation timestamp.'],
                ],
            ],
            'Layer' => [
                'description' => 'One addressable layer of a template, as the template endpoints return it. The response has no `type` field.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'layer', 'type' => 'string|null', 'required' => true, 'description' => 'Layer key used when overriding content.'],
                    [
                        'name' => 'available_modifications',
                        'type' => 'object',
                        'required' => true,
                        // `TemplateLayerParser` builds this with `mapWithKeys()`.
                        // A layer with a current value encodes as an object. A
                        // layer with none encodes as an empty JSON array.
                        'schema' => ['oneOf' => [
                            ['type' => 'object', 'minProperties' => 1, 'additionalProperties' => ['type' => 'string', 'nullable' => true]],
                            ['type' => 'array', 'maxItems' => 0, 'items' => ['type' => 'string']],
                        ]],
                        'description' => 'Maps each override key that this layer accepts (image_url, text, qr_content, barcode_content) to its current value. '
                            .'A layer with no override, for example an empty or svg layer, returns an empty array `[]`, not `{}`.',
                    ],
                ],
            ],
            'Screenshot' => [
                'description' => 'A rendered capture of a web page, as the read and the create return it under `data`. The id field is `id`.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'id', 'type' => 'string', 'required' => true, 'description' => 'Screenshot UUID.'],
                    ['name' => 'url', 'type' => 'string', 'required' => true, 'description' => 'Captured page URL.'],
                    ['name' => 'image_url', 'type' => 'string|null', 'required' => true, 'description' => 'URL of the rendered image.'],
                    ['name' => 'width', 'type' => 'integer', 'required' => true, 'description' => 'Viewport width in pixels.'],
                    ['name' => 'height', 'type' => 'integer', 'required' => true, 'description' => 'Viewport height in pixels.'],
                    ['name' => 'created_at', 'type' => 'datetime', 'required' => true, 'description' => 'ISO-8601 creation timestamp.'],
                ],
            ],
            'ScreenshotListItem' => [
                'description' => 'One screenshot in the list of GET /v1/screenshots. The id field is `uuid`, not `id`.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'uuid', 'type' => 'string', 'required' => true, 'description' => 'Screenshot UUID. The same value as `id` in the read.'],
                    ['name' => 'url', 'type' => 'string', 'required' => true, 'description' => 'Captured page URL.'],
                    ['name' => 'image_url', 'type' => 'string|null', 'required' => true, 'description' => 'URL of the rendered image.'],
                    ['name' => 'width', 'type' => 'integer', 'required' => true, 'description' => 'Viewport width in pixels.'],
                    ['name' => 'height', 'type' => 'integer', 'required' => true, 'description' => 'Viewport height in pixels.'],
                    ['name' => 'created_at', 'type' => 'datetime', 'required' => true, 'description' => 'ISO-8601 creation timestamp.'],
                ],
            ],
            'Snap' => [
                'description' => 'A render job produced from a template or collection. POST /v1/snaps returns it with no `data` wrapper.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'id', 'type' => 'string', 'required' => true, 'description' => 'Snap UUID.'],
                    ['name' => 'media_url', 'type' => 'string', 'required' => true, 'description' => 'URL of the finished asset. An empty string when the snap has no finished asset: pending, processing, failed or canceled. Never null. A batch item uses null for the same state.'],
                    ['name' => 'width', 'type' => 'integer|null', 'required' => true, 'description' => 'Width in pixels of the version that rendered. Null when the snap has no source.'],
                    ['name' => 'height', 'type' => 'integer|null', 'required' => true, 'description' => 'Height in pixels of the version that rendered. Null when the snap has no source.'],
                    ['name' => 'type', 'type' => 'string', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['image', 'pdf']], 'description' => 'image or pdf.'],
                    ['name' => 'status', 'type' => 'string', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['pending', 'processing', 'completed', 'failed', 'canceled']], 'description' => 'pending, processing, completed, failed or canceled.'],
                    ['name' => 'is_async', 'type' => 'boolean', 'required' => true, 'description' => 'Whether the snap was queued.'],
                    ['name' => 'webhook_url', 'type' => 'string|null', 'required' => true, 'description' => 'Callback URL for async completion.'],
                    ['name' => 'completed_at', 'type' => 'datetime|null', 'required' => true, 'description' => 'When rendering finished.'],
                    ['name' => 'last_error', 'type' => 'string|null', 'required' => true, 'description' => 'A fixed code, when status is failed: `render_failed`, or a webhook code (`webhook_address_refused`, `webhook_redirect_refused`, `webhook_unreachable`, `webhook_http_<status>`). Null otherwise.'],
                    ['name' => 'warnings', 'type' => 'RenderWarning[]', 'required' => true, 'description' => 'Problems of the render that did not stop it, for example a font that the server could not load. An empty list when there are none.'],
                    ['name' => 'variants', 'type' => 'Variant[]|null', 'required' => true, 'schema' => ['type' => 'array', 'nullable' => true, 'items' => ['$ref' => 'Variant']], 'description' => 'The variants applied, as sent. Null when the request sent none.'],
                    ['name' => 'template_id', 'type' => 'string|null', 'required' => true, 'description' => 'Source template UUID. Null for a snap rendered from an inline design, which has no template.'],
                    ['name' => 'template_title', 'type' => 'string|null', 'required' => true, 'description' => 'Source template name. Null for a snap rendered from an inline design.'],
                    ['name' => 'created_at', 'type' => 'datetime', 'required' => true, 'description' => 'ISO-8601 creation timestamp.'],
                ],
            ],
            'RenderWarning' => [
                'description' => 'One problem of a render that did not stop it.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'code', 'type' => 'string', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['font_unavailable']], 'description' => '`font_unavailable`: the server could not load the font, so the image can show a fallback font.'],
                    ['name' => 'family', 'type' => 'string', 'required' => true, 'description' => 'The font family of the warning.'],
                    ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'A sentence that a person can read.'],
                ],
            ],
            'Batch' => [
                'description' => 'The progress of a batch. The create returns it with no `items`. The read and the cancel add `items`. No `data` wrapper.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'uuid', 'type' => 'string', 'required' => true, 'description' => 'Batch UUID.'],
                    ['name' => 'title', 'type' => 'string', 'required' => true, 'description' => 'Batch name.'],
                    ['name' => 'status', 'type' => 'string', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['pending', 'processing', 'completed', 'failed', 'canceled']], 'description' => 'pending, processing, completed, failed or canceled.'],
                    ['name' => 'total_rows', 'type' => 'integer', 'required' => true, 'description' => 'Number of items.'],
                    ['name' => 'processed_rows', 'type' => 'integer', 'required' => true, 'description' => 'completed + failed + canceled.'],
                    ['name' => 'completed', 'type' => 'integer', 'required' => true, 'description' => 'Items that rendered.'],
                    ['name' => 'pending', 'type' => 'integer', 'required' => true, 'description' => 'Items that wait or render now.'],
                    ['name' => 'failed', 'type' => 'integer', 'required' => true, 'description' => 'Items that failed.'],
                    ['name' => 'canceled', 'type' => 'integer', 'required' => true, 'description' => 'Items that a cancel stopped.'],
                    ['name' => 'status_url', 'type' => 'string', 'required' => true, 'description' => 'URL of GET /v1/snaps/batches/{batchUuid}.'],
                    ['name' => 'items', 'type' => 'BatchItem[]', 'description' => 'Every item. Only the read and the cancel return it.'],
                ],
            ],
            'BatchItem' => [
                'description' => 'One item of a batch.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'id', 'type' => 'string', 'required' => true, 'description' => 'Snap UUID.'],
                    ['name' => 'identifier', 'type' => 'string|null', 'required' => true, 'description' => 'Your name for the item, or item_1, item_2 and so on.'],
                    ['name' => 'status', 'type' => 'string', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['pending', 'processing', 'completed', 'failed', 'canceled']], 'description' => 'Item status.'],
                    ['name' => 'media_url', 'type' => 'string|null', 'required' => true, 'description' => 'Image URL when the status is completed. Null when the item is not complete: pending, processing, failed or canceled. Never an empty string. The snap object uses an empty string for the same state.'],
                    ['name' => 'last_error', 'type' => 'string|null', 'required' => true, 'description' => 'A fixed code, when the item failed: `render_failed`. Null otherwise.'],
                ],
            ],
            'Media' => [
                'description' => 'One image in your media library. The upload and the list return these fields and no other field.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'id', 'type' => 'integer', 'required' => true, 'description' => 'Numeric media id.'],
                    ['name' => 'uuid', 'type' => 'string', 'required' => true, 'description' => 'Media UUID.'],
                    ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'File name without the extension.'],
                    ['name' => 'file_name', 'type' => 'string', 'required' => true, 'description' => 'Stored file name.'],
                    ['name' => 'mime_type', 'type' => 'string|null', 'required' => true, 'description' => 'MIME type of the upload.'],
                    ['name' => 'size', 'type' => 'integer', 'required' => true, 'description' => 'File size in bytes.'],
                    ['name' => 'original_url', 'type' => 'string', 'required' => true, 'description' => 'URL of the original file.'],
                    ['name' => 'src', 'type' => 'string', 'required' => true, 'description' => 'URL of the `image` conversion. Use it as an image_url variant.'],
                    ['name' => 'created_at', 'type' => 'datetime', 'required' => true, 'description' => 'ISO-8601 upload timestamp.'],
                ],
            ],
            'MediaPage' => [
                'description' => 'A flat Laravel length-aware paginator: the page fields sit next to `data`, with no `meta` key.',
                'fields' => [
                    ['name' => 'current_page', 'type' => 'integer', 'required' => true, 'description' => 'Page number.'],
                    ['name' => 'data', 'type' => 'Media[]', 'required' => true, 'description' => 'The media on this page, newest first.'],
                    ['name' => 'first_page_url', 'type' => 'string', 'required' => true, 'description' => 'URL of page 1.'],
                    ['name' => 'from', 'type' => 'integer|null', 'required' => true, 'description' => 'Position of the first item on this page.'],
                    ['name' => 'last_page', 'type' => 'integer', 'required' => true, 'description' => 'Number of the last page.'],
                    ['name' => 'last_page_url', 'type' => 'string', 'required' => true, 'description' => 'URL of the last page.'],
                    ['name' => 'links', 'type' => 'PaginatorLink[]', 'required' => true, 'description' => 'Page links for a pager.'],
                    ['name' => 'next_page_url', 'type' => 'string|null', 'required' => true, 'description' => 'URL of the next page, or null.'],
                    ['name' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Base URL of the list.'],
                    ['name' => 'per_page', 'type' => 'integer', 'required' => true, 'description' => 'Items per page (12).'],
                    ['name' => 'prev_page_url', 'type' => 'string|null', 'required' => true, 'description' => 'URL of the previous page, or null.'],
                    ['name' => 'to', 'type' => 'integer|null', 'required' => true, 'description' => 'Position of the last item on this page.'],
                    ['name' => 'total', 'type' => 'integer', 'required' => true, 'description' => 'Number of media in the library.'],
                ],
            ],
            'PaginatorLink' => [
                'description' => 'One link of a Laravel paginator.',
                'fields' => [
                    ['name' => 'url', 'type' => 'string|null', 'required' => true, 'description' => 'Page URL, or null.'],
                    ['name' => 'label', 'type' => 'string', 'required' => true, 'description' => 'Link label.'],
                    ['name' => 'active', 'type' => 'boolean', 'required' => true, 'description' => 'True for the current page.'],
                ],
            ],
            'CursorMeta' => [
                'description' => 'The cursor of a cursor-paginated list.',
                'fields' => [
                    ['name' => 'previous_cursor', 'type' => 'string|null', 'required' => true, 'description' => 'Cursor of the previous page, or null.'],
                    ['name' => 'next_cursor', 'type' => 'string|null', 'required' => true, 'description' => 'Cursor of the next page, or null.'],
                ],
            ],
            'Error' => [
                'description' => 'An error with a message only.',
                'fields' => [
                    ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'What went wrong.'],
                ],
            ],
            'InternalError' => [
                'description' => 'A 500 error. The server could not complete the request. The body holds no detail of the cause. Quote the `X-Request-Uuid` header in a support ticket.',
                'additionalProperties' => false,
                'fields' => [
                    ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'A fixed message for a person to read.'],
                    ['name' => 'code', 'type' => 'string', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['internal_error']], 'description' => 'Always `internal_error`.'],
                ],
            ],
            'EditorDocumentConflict' => [
                'description' => 'A 409 on an editor document write. The stored revision moved, so nothing was saved. Rebuild the change on `current_document` and send it with `current_revision`.',
                'fields' => [
                    ['name' => 'code', 'type' => 'string', 'required' => true, 'schema' => ['type' => 'string', 'enum' => ['editor_document_conflict']], 'description' => 'Always `editor_document_conflict`.'],
                    ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'What went wrong and what to do. Do not branch on this text.'],
                    ['name' => 'current_revision', 'type' => 'integer', 'required' => true, 'description' => 'The stored revision.'],
                    ['name' => 'current_document', 'type' => 'object', 'required' => true, 'description' => 'The stored document.'],
                ],
            ],
            'EditorOperationError' => [
                'description' => 'A 422 when the document rejects an operation. `code` names the rule, for example `editor_operation_layer_not_found`.',
                'fields' => [
                    ['name' => 'code', 'type' => 'string', 'required' => true, 'description' => 'A stable error code.'],
                    ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'What went wrong and how to correct the operation. Do not branch on this text.'],
                ],
            ],
            'ValidationError' => [
                'description' => 'A 422 validation error. `errors` maps each field to its messages.',
                'fields' => [
                    ['name' => 'message', 'type' => 'string', 'required' => true, 'description' => 'The first message.'],
                    ['name' => 'errors', 'type' => 'object', 'required' => true, 'schema' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]], 'description' => 'Messages per field, for example `errors.template_id`.'],
                ],
            ],
        ];
    }

    /**
     * The override properties of a variant, with their schema. The names are
     * the ones `App\Support\VariantRules` accepts and the descriptions are the
     * ones `App\Support\VariantProperties` serves to the MCP agent.
     *
     * @return list<array{name: string, schema: array<string, mixed>, description: string}>
     */
    private static function variantProperties(): array
    {
        $schemas = [
            'text' => ['type' => 'string'],
            'image_url' => ['type' => 'string', 'format' => 'uri'],
            'background_color' => ['type' => 'string'],
            'qr_content' => ['type' => 'string'],
            'barcode_content' => ['type' => 'string', 'maxLength' => 1000],
            'hidden' => ['type' => 'boolean'],
        ];

        return array_map(fn (array $entry) => [
            'name' => $entry['property'],
            'schema' => $schemas[$entry['property']],
            'description' => $entry['description'],
        ], VariantProperties::catalog());
    }

    /**
     * Documentation groups in nav order. Each group renders one docs screen.
     */
    public static function groups(): array
    {
        $groups = [
            self::groupAuthentication(),
            self::groupGuides(),
            self::groupTemplates(),
            self::groupEditorDocument(),
            self::groupMedia(),
            self::groupScreenshots(),
            self::groupSnaps(),
            self::groupSnapBatches(),
            self::groupPreview(),
            self::groupSignedUrls(),
            self::groupTags(),
            self::groupEmbeddedForms(),
        ];

        return self::localize(array_map(self::withTokenErrors(...), $groups));
    }

    /**
     * Every bearer route sits behind `auth:sanctum` and `abilities:api_requests`,
     * so each one can answer 401 and 403. An endpoint that documents its own 401
     * or 403 keeps it, because its description says more.
     */
    private static function withTokenErrors(array $group): array
    {
        $tokenErrors = [
            ['status' => 401, 'description' => 'Missing or invalid token.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'Unauthenticated.']],
            ['status' => 403, 'description' => 'The token lacks the api_requests ability.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'Invalid ability provided.']],
        ];

        foreach ($group['endpoints'] as $index => $endpoint) {
            if ($endpoint['auth'] !== 'bearer') {
                continue;
            }

            $documented = array_column($endpoint['responses'] ?? [], 'status');

            foreach ($tokenErrors as $error) {
                if (! in_array($error['status'], $documented, true)) {
                    $group['endpoints'][$index]['responses'][] = $error;
                }
            }
        }

        return $group;
    }

    /** Flat list of every endpoint across all groups. */
    public static function endpoints(): array
    {
        $out = [];
        foreach (self::groups() as $group) {
            foreach ($group['endpoints'] as $endpoint) {
                $out[] = $endpoint + ['group' => $group['slug']];
            }
        }

        return $out;
    }

    public static function group(string $slug): ?array
    {
        foreach (self::groups() as $group) {
            if ($group['slug'] === $slug) {
                return $group;
            }
        }

        return null;
    }

    /**
     * The schema of a body that wraps one resource in `data`, and adds
     * `message` on a write.
     */
    private static function wrapped(string $schema, bool $message = false): array
    {
        $out = [
            'type' => 'object',
            'required' => $message ? ['data', 'message'] : ['data'],
            'properties' => ['data' => ['$ref' => $schema]],
        ];

        if ($message) {
            $out['properties']['message'] = ['type' => 'string'];
        }

        return $out;
    }

    /** The schema of a cursor-paginated list: `data` and `meta`. */
    private static function cursorPage(string $schema): array
    {
        return [
            'type' => 'object',
            'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => $schema]],
                'meta' => ['$ref' => 'CursorMeta'],
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Groups
    // ---------------------------------------------------------------------
    private static function groupAuthentication(): array
    {
        return [
            'slug' => 'authentication',
            'title' => 'Authentication',
            'tagline' => 'One bearer token, every request.',
            'description' => 'Authenticated endpoints use a Sanctum personal access '
                .'token. Send it as a bearer token in the Authorization header. '
                ."Tokens must carry the `api_requests` ability.\n\n"
                .'    Authorization: Bearer <your-token>'."\n"
                .'    Accept: application/json'
                ."\n\nAn endpoint that takes a bearer token answers each error with a JSON body, also when the request has no Accept header.",
            'endpoints' => [
                [
                    'id' => 'auth.show',
                    'method' => 'GET',
                    'path' => '/v1/auth',
                    'summary' => 'Verify the current token',
                    'description' => 'Returns the account the token belongs to, with the '
                        .'company it belongs to. Use it to confirm a token is valid and to '
                        .'read the signed-in identity. `company` is null when the account '
                        .'belongs to no company.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'Token is valid.', 'example' => ['data' => ['email' => 'dev@example.com', 'name' => 'Ada Lovelace', 'company' => 'Analytical Engines']]],
                        ['status' => 401, 'description' => 'Missing or invalid token.', 'example' => ['message' => 'Unauthenticated.']],
                        ['status' => 403, 'description' => 'Token lacks the api_requests ability.', 'example' => ['message' => 'Invalid ability provided.']],
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/auth \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Accept: application/json'",
                    ],
                ],
            ],
        ];
    }

    private static function groupGuides(): array
    {
        return [
            'slug' => 'guides',
            'title' => 'Guides',
            'tagline' => 'Read the recipe before you cook.',
            'description' => 'Guides are static reference catalogs. They tell you which '
                .'layer properties exist, which overrides a layer accepts, how to compose '
                .'a template that looks good, and what the v2 editor document contains. '
                .'They also tell you what a platform does to an image after you upload it, '
                .'and what a given kind of image has to achieve. '
                .'The content never changes between accounts, so you can cache a response '
                ."and reuse it.\n\n"
                .'These endpoints read no template and no company data. They also stay '
                .'outside the usage log, so a guide call never appears there.',
            'endpoints' => [
                self::guideEndpoint(
                    'guides.design',
                    '/v1/guides/design',
                    'Get the design guide',
                    'Returns design guidance for building a template: canvas presets, the '
                        .'back-to-front layer order, typography and color rules, composition '
                        .'recipes, anti-patterns, and a preflight checklist. Read it before '
                        .'you write a `layers` array.',
                    ['typography' => ['scale' => 'On a 1080px canvas: headline 64–120px, subhead 36–56px, body 24–36px.']],
                ),
                self::guideEndpoint(
                    'guides.layers',
                    '/v1/guides/layers',
                    'Get the layer schema',
                    'Returns the design-time layer vocabulary: the layer object shape, every '
                        .'property name, the value shape of each property, and a worked '
                        .'example. Use it to build the `layers` array a template write accepts.',
                    ['property_names' => ['textContent', 'backgroundType', 'fontFamily']],
                ),
                self::guideEndpoint(
                    'guides.variant-properties',
                    '/v1/guides/variant-properties',
                    'List variant properties',
                    'Returns every property you can apply to a layer in `variants`, with its '
                        .'value type, the layer types it applies to, and an example. Pair it '
                        .'with the `available_modifications` of a template layer.',
                    [[
                        'property' => 'text',
                        'value_type' => 'string',
                        'applies_to' => 'text layers',
                        'description' => "Replace a text layer's content.",
                        'example' => ['layer' => 'headline', 'text' => 'Summer Sale — 40% off'],
                    ]],
                ),
                self::guideEndpoint(
                    'guides.editor-document',
                    '/v1/guides/editor-document',
                    'Get the editor document contract',
                    'Returns the versioned v2 editor-document contract: the resource it '
                        .'describes, property constraints, persistence rules, conflict '
                        .'recovery, and a safe workflow. Read it before you change an editor '
                        .'document.',
                    ['contract_version' => 2, 'editor_document_version' => 2, 'resource' => 'template.editor_document'],
                ),
                self::guideEndpoint(
                    'guides.platforms',
                    '/v1/guides/platforms',
                    'List platform guides',
                    'Returns one line per platform: the size preset to start from, its '
                        .'dimensions and why that preset is the default. Read it to choose a '
                        .'platform, then read that platform for the full rules. The response '
                        .'also carries `specs_reviewed`, the month the published specs were '
                        .'last checked.',
                    [['platform' => 'instagram', 'name' => 'Instagram', 'start_here' => 'instagram_post', 'dimensions' => '1080x1350', 'why' => 'A 4:5 portrait post fills the most vertical space the feed will give a single image.']],
                ),
                self::guideDetailEndpoint(
                    id: 'guides.platform',
                    path: '/v1/guides/platforms/{platform}',
                    parameter: 'platform',
                    summary: 'Get one platform guide',
                    description: 'Returns everything one platform does to an image: how it crops the '
                        .'frame, the directional UI safe zones where it paints its own '
                        .'controls, the accepted formats and size limits, the export width '
                        .'and color profile, its size presets, and its rules and '
                        .'anti-patterns.',
                    sampleKey: 'instagram',
                    sampleAlias: 'insta',
                    example: ['platform' => 'instagram', 'name' => 'Instagram', 'specs_reviewed' => PlatformGuide::SPECS_REVIEWED, 'start_here' => 'instagram_post'],
                    notFoundMessage: 'No platform guide matches `myspace`.',
                    available: PlatformGuide::keys(),
                ),
                self::guideEndpoint(
                    'guides.purposes',
                    '/v1/guides/purposes',
                    'List image purpose guides',
                    'Returns one line per purpose: what that kind of image is, when to use '
                        .'it and the size presets that fit it. Read it to choose a purpose, '
                        .'then read that purpose for the full rules.',
                    [['purpose' => 'link_preview', 'name' => 'Link preview / meta tag image', 'use_when' => 'You are shipping a page and you want the shared link to look deliberate.', 'sizes' => ['open_graph', 'twitter_card']]],
                ),
                self::guideDetailEndpoint(
                    id: 'guides.purpose',
                    path: '/v1/guides/purposes/{purpose}',
                    parameter: 'purpose',
                    summary: 'Get one image purpose guide',
                    description: 'Returns the job one kind of image has to do: the size presets that fit '
                        .'it, the rules and failure modes specific to it, a layer recipe in '
                        .'the Snapsbrew vocabulary, an anti-pattern list and a preflight '
                        .'checklist. The `link_preview` purpose also returns the og: and '
                        .'twitter: meta tags to put in the page head.',
                    sampleKey: 'link_preview',
                    sampleAlias: 'og',
                    example: ['purpose' => 'link_preview', 'name' => 'Link preview / meta tag image', 'layer_recipe' => 'base fill -> optional photo with a dark scrim -> headline -> supporting line -> logo.'],
                    notFoundMessage: 'No purpose guide matches `wallpaper`.',
                    available: ImagePurposeGuide::keys(),
                ),
            ],
        ];
    }

    /**
     * Build one guide endpoint. The guides share a shape: a bearer token,
     * no parameters, no body and a `data` envelope.
     */
    private static function guideEndpoint(string $id, string $path, string $summary, string $description, array $example): array
    {
        return [
            'id' => $id,
            'method' => 'GET',
            'path' => $path,
            'summary' => $summary,
            'description' => $description,
            'auth' => 'bearer',
            'abilities' => ['api_requests'],
            'middleware' => [],
            'parameters' => [],
            'body' => null,
            'responses' => [
                ['status' => 200, 'description' => 'The catalog, trimmed here to one entry.', 'example' => ['data' => $example]],
                ['status' => 401, 'description' => 'Missing or invalid token.', 'example' => ['message' => 'Unauthenticated.']],
            ],
            'notes' => [
                'The catalog is the same for every account. Cache the response and refresh it on a new API version.',
                'A guide call does not appear in the usage log.',
            ],
            'examples' => [
                'curl' => "curl https://snapsbrew.com/api{$path} \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Accept: application/json'",
            ],
        ];
    }

    /**
     * Build one guide detail endpoint. It takes the shared guide shape, then
     * adds the parts that a lookup by key needs: the path parameter, the 404,
     * the 422, an alias note, and a curl example that calls a real key.
     *
     * @param  array<string, mixed>  $example
     * @param  array<int, string>  $available
     */
    private static function guideDetailEndpoint(
        string $id,
        string $path,
        string $parameter,
        string $summary,
        string $description,
        string $sampleKey,
        string $sampleAlias,
        array $example,
        string $notFoundMessage,
        array $available
    ): array {
        $endpoint = self::guideEndpoint($id, $path, $summary, $description, $example);
        $withKey = str_replace('{'.$parameter.'}', $sampleKey, $path);
        $withAlias = str_replace('{'.$parameter.'}', $sampleAlias, $path);

        $endpoint['parameters'] = [
            [
                'name' => $parameter,
                'in' => 'path',
                'type' => 'string',
                'required' => true,
                'description' => 'A key from the index route, or a common alias. '
                    .'Maximum 64 characters. Valid keys: '.implode(', ', $available).'.',
            ],
        ];

        $endpoint['responses'][] = [
            'status' => 404,
            'description' => 'No guide matches the key. The body names every valid key.',
            'schema' => [
                'type' => 'object',
                'required' => ['message', 'available'],
                'properties' => ['message' => ['type' => 'string'], 'available' => ['type' => 'array', 'items' => ['type' => 'string']]],
            ],
            'example' => ['message' => $notFoundMessage, 'available' => $available],
        ];

        $endpoint['responses'][] = [
            'status' => 422,
            'description' => 'The key is longer than 64 characters.',
            'example' => ['message' => "The {$parameter} must not be greater than 64 characters.", 'errors' => [$parameter => ["The {$parameter} must not be greater than 64 characters."]]],
        ];

        $endpoint['notes'][] = "An alias is accepted. `{$withAlias}` returns the same guide as `{$withKey}`.";

        $endpoint['examples']['curl'] = str_replace('{'.$parameter.'}', $sampleKey, $endpoint['examples']['curl']);

        return $endpoint;
    }

    private static function groupTemplates(): array
    {
        return [
            'slug' => 'templates',
            'title' => 'Templates',
            'tagline' => 'The menu you render from.',
            'description' => 'Templates are reusable designs with addressable layers. '
                .'List and read them, or manage them programmatically. All template '
                .'endpoints are scoped to your company.',
            'endpoints' => [
                [
                    'id' => 'templates.index',
                    'method' => 'GET',
                    'path' => '/v1/templates',
                    'summary' => 'List templates',
                    'description' => 'Returns a cursor-paginated list of your templates.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [
                        ['name' => 'cursor', 'in' => 'query', 'type' => 'string', 'required' => false, 'description' => 'Cursor token from a previous response\'s meta.next_cursor.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'A page of templates.', 'schema' => self::cursorPage('TemplateListItem'), 'example' => [
                            'data' => [[
                                'uuid' => '141e8454-8aa1-420a-bd0b-18a85ba949a2',
                                'title' => 'Template 1',
                                'width' => 1200,
                                'height' => 630,
                                'layers' => [['layer' => 'review_title', 'available_modifications' => ['text' => 'The worst Time-Travel movie ever made']]],
                                'created_at' => '2026-05-01T12:00:00Z',
                            ]],
                            'meta' => ['previous_cursor' => null, 'next_cursor' => 'eyJpZCI6MTB9'],
                        ]],
                        ['status' => 422, 'description' => 'A query parameter failed a rule.', 'schema' => ['$ref' => 'ValidationError'], 'example' => ['message' => 'The cursor must be a string.', 'errors' => ['cursor' => ['The cursor must be a string.']]]],
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/templates \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'templates.sizes',
                    'method' => 'GET',
                    'path' => '/v1/templates/sizes',
                    'summary' => 'List canvas size presets',
                    'description' => 'Returns every canvas size preset a template write accepts as `size` — '
                        .'Instagram, Facebook, X, LinkedIn, Threads, Pinterest, TikTok, YouTube, Snapchat, '
                        .'WhatsApp, web and email, display ads, and print. Each preset gives its dimensions, '
                        .'aspect ratio, and the safe margin to keep content away from the edges.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'The preset catalog.', 'example' => [
                            'data' => [
                                ['size' => 'instagram_story', 'name' => 'Instagram Story (1080x1920)', 'platform' => 'Instagram', 'width' => 1080, 'height' => 1920, 'aspect_ratio' => '9:16', 'safe_margin_px' => 97],
                                ['size' => 'open_graph', 'name' => 'Open Graph / Link Preview (1200x630)', 'platform' => 'Web & email', 'width' => 1200, 'height' => 630, 'aspect_ratio' => '1.9:1', 'safe_margin_px' => 47],
                            ],
                        ]],
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/templates/sizes \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'templates.show',
                    'method' => 'GET',
                    'path' => '/v1/templates/{templateUuid}',
                    'summary' => 'Get a template',
                    'description' => 'Returns a single template, its parsed layers and its publish '
                        .'state. The layers are the draft, so read `has_unpublished_edits` to know '
                        .'whether the draft is what renders.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [
                        ['name' => 'templateUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'The template.', 'schema' => self::wrapped('Template'), 'example' => [
                            'data' => [
                                'uuid' => '141e8454-8aa1-420a-bd0b-18a85ba949a2',
                                'title' => 'Template 1',
                                'size' => 'open_graph',
                                'width' => 1200,
                                'height' => 630,
                                'layers' => [
                                    ['layer' => 'review_poster', 'available_modifications' => ['image_url' => 'https://snapsbrew.com/crazy-doc-brown-jailed']],
                                    ['layer' => 'review_title', 'available_modifications' => ['text' => 'The worst Time-Travel movie ever made']],
                                    ['layer' => 'frame', 'available_modifications' => []],
                                ],
                                'published_version' => 2,
                                'has_unpublished_edits' => true,
                                'created_at' => '2026-05-01T12:00:00Z',
                            ],
                        ]],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                    ],
                    'notes' => [
                        'BREAKING CHANGE. The template fields moved under a `data` key. Read `data.uuid`, not `uuid`. The template, auth, guide, screenshot and tag endpoints wrap the resource in `data`. A list adds `meta` and a write adds `message`.',
                        'Not every success body has a `data` key. POST /v1/snaps, the batch endpoints, the media endpoints and the editor document endpoints return the object with no wrapper. GET /v1/media returns a flat Laravel paginator.',
                        'A layer has no `type` field. `available_modifications` maps each override key to its current value, and a layer with no override returns an empty array `[]`.',
                        '`size` is the canvas size preset the template is stored under, the same value POST /v1/templates returns. It reads `custom` when the dimensions match no preset. GET /v1/templates/sizes lists every preset.',
                        '`published_version` is the version number snaps and previews render. It is null until you publish the template.',
                        '`has_unpublished_edits` is true when the draft is not the same as the published version. A layer edit, a resize and an editor-document edit all set it. Call POST /v1/templates/{templateUuid}/publish to make the draft render.',
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/templates/<uuid> \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'templates.store',
                    'method' => 'POST',
                    'path' => '/v1/templates',
                    'summary' => 'Create a template',
                    'description' => 'Creates a template and generates its preview image. '
                        .'Requires the create-template policy.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['policy:create,Template'],
                    'parameters' => [],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'title', 'type' => 'string', 'required' => true, 'rules' => 'required|string|max:255', 'description' => 'Template name.'],
                            ['name' => 'size', 'type' => 'string', 'required' => false, 'rules' => 'nullable|in:<preset>', 'description' => 'Canvas size preset, for example "instagram_story" or "youtube_thumbnail". It sets width and height for you. GET /v1/templates/sizes lists every preset.'],
                            ['name' => 'width', 'type' => 'integer', 'required' => false, 'rules' => 'required_without:size|numeric|min:1|max:4000', 'description' => 'Canvas width in pixels. Required when you send no size preset. Ignored when you send a size preset.'],
                            ['name' => 'height', 'type' => 'integer', 'required' => false, 'rules' => 'required_without:size|numeric|min:1|max:4000', 'description' => 'Canvas height in pixels. Required when you send no size preset. Ignored when you send a size preset.'],
                            ['name' => 'layers', 'type' => 'array', 'items' => ['$ref' => 'LayerDefinition'], 'required' => false, 'rules' => 'nullable|array, each layer needs a unique key and a properties array', 'description' => 'Layer objects; stored JSON-encoded. An absent or null list creates an empty canvas.'],
                            ['name' => 'groups', 'type' => 'array', 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string', 'maxLength' => 64], 'name' => ['type' => 'string', 'nullable' => true, 'maxLength' => 255], 'visible' => ['type' => 'boolean'], 'collapsed' => ['type' => 'boolean'], 'order' => ['type' => 'integer']]], 'required' => false, 'rules' => 'nullable|array, each group needs an id', 'description' => 'Editor layer groups, in the shape that the editor saves. Send null to remove the groups.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or your account is not allowed to create templates.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 200, 'description' => 'Template created.', 'schema' => self::wrapped('TemplateSummary', true), 'example' => [
                            'data' => [
                                'uuid' => '141e8454-8aa1-420a-bd0b-18a85ba949a2',
                                'title' => 'Launch card',
                                'size' => 'open_graph',
                                'width' => 1200,
                                'height' => 630,
                                'created_at' => '2026-05-01T12:00:00Z',
                            ],
                            'message' => 'Template created successfully',
                        ]],
                        ['status' => 422, 'description' => 'The body names no canvas, or a field failed a rule.', 'example' => [
                            'message' => 'Send a `size` preset or an explicit `width` and `height`.',
                            'errors' => [
                                'width' => ['Send a `size` preset or an explicit `width` and `height`.'],
                                'height' => ['Send a `size` preset or an explicit `width` and `height`.'],
                            ],
                        ]],
                    ],
                    'notes' => [
                        'Send a `size` preset or an explicit `width`/`height`. A template saved with dimensions that match a preset is stored under that preset.',
                        'A body with no `size` and no `width`/`height` answers 422. A create has no stored canvas to fall back on.',
                        'The response holds the six fields above and nothing more. Read GET /v1/templates/{templateUuid} for the parsed layers and the publish state.',
                    ],
                    'examples' => [
                        'curl' => "curl -X POST https://snapsbrew.com/api/v1/templates \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"title\":\"Launch card\",\"size\":\"open_graph\",\"layers\":[]}'",
                    ],
                ],
                [
                    'id' => 'templates.update',
                    'method' => 'PATCH',
                    'path' => '/v1/templates/{templateIdentifier}',
                    'summary' => 'Update a template',
                    'description' => 'Updates a template. It renders a new preview image only when '
                        .'`layers`, `groups`, `width` or `height` changes, or when the template has no preview. '
                        .'A repeat of the same request, or a change to the title only, keeps the preview image and its URL. '
                        .'The path segment accepts the template UUID or the numeric template ID. '
                        .'GET /v1/templates returns the UUID.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => [],
                    'parameters' => [
                        ['name' => 'templateIdentifier', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Template UUID or numeric template ID, scoped to your company. A value of only digits reads the numeric ID. Every other value reads the UUID.'],
                    ],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'title', 'type' => 'string', 'required' => true, 'rules' => 'required|string|max:255', 'description' => 'Template name.'],
                            ['name' => 'size', 'type' => 'string', 'required' => false, 'rules' => 'nullable|in:<preset>', 'description' => 'Canvas size preset, for example "instagram_story" or "youtube_thumbnail". It sets width and height for you. GET /v1/templates/sizes lists every preset.'],
                            ['name' => 'width', 'type' => 'integer', 'required' => false, 'rules' => 'nullable|numeric|min:1|max:4000', 'description' => 'Canvas width in pixels. Ignored when you send a size preset.'],
                            ['name' => 'height', 'type' => 'integer', 'required' => false, 'rules' => 'nullable|numeric|min:1|max:4000', 'description' => 'Canvas height in pixels. Ignored when you send a size preset.'],
                            ['name' => 'layers', 'type' => 'array', 'items' => ['$ref' => 'LayerDefinition'], 'required' => false, 'rules' => 'nullable|array, each layer needs a unique key and a properties array', 'description' => 'Layer objects; stored JSON-encoded.'],
                            ['name' => 'groups', 'type' => 'array', 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string', 'maxLength' => 64], 'name' => ['type' => 'string', 'nullable' => true, 'maxLength' => 255], 'visible' => ['type' => 'boolean'], 'collapsed' => ['type' => 'boolean'], 'order' => ['type' => 'integer']]], 'required' => false, 'rules' => 'nullable|array, each group needs an id', 'description' => 'Editor layer groups, in the shape that the editor saves. Send null to remove the groups.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or your account is not allowed to change templates of this company.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 200, 'description' => 'Template updated.', 'schema' => self::wrapped('TemplateSummary', true), 'example' => [
                            'data' => [
                                'uuid' => '141e8454-8aa1-420a-bd0b-18a85ba949a2',
                                'title' => 'Updated title',
                                'size' => 'open_graph',
                                'width' => 1200,
                                'height' => 630,
                                'created_at' => '2026-05-01T12:00:00Z',
                            ],
                            'message' => 'Template updated successfully',
                        ]],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                        ['status' => 409, 'description' => 'The template uses the v2 editor document. This route cannot edit it. Use PATCH or PUT /v1/templates/{templateUuid}/document. Nothing was saved.', 'schema' => [
                            'type' => 'object',
                            'required' => ['code', 'message'],
                            'properties' => ['code' => ['type' => 'string', 'enum' => ['legacy_edit_rejected_for_v2']], 'message' => ['type' => 'string']],
                        ], 'example' => ['code' => 'legacy_edit_rejected_for_v2', 'message' => 'This template has a v2 editor document, so this route cannot change it. Read GET /v1/templates/{templateUuid}/document, then send PATCH or PUT /v1/templates/{templateUuid}/document with its revision.']],
                        ['status' => 422, 'description' => 'A field failed a rule.', 'schema' => ['$ref' => 'ValidationError'], 'example' => ['message' => 'The title field is required.', 'errors' => ['title' => ['The title field is required.']]]],
                    ],
                    'notes' => [
                        '`data` holds the same six fields POST /v1/templates returns. It is a new key, so a client that reads `message` still works.',
                        'Read `data.size`, `data.width` and `data.height` after the write. A body that sends `size` sets the dimensions, and a body that sends the dimensions sets `size`.',
                        'The preview render runs before the save. If the render fails, the request answers 500 and saves nothing. Send the same request again.',
                    ],
                    'examples' => [
                        'curl' => "curl -X PATCH https://snapsbrew.com/api/v1/templates/<uuid> \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"title\":\"Updated title\"}'",
                    ],
                ],
                [
                    'id' => 'templates.publish',
                    'method' => 'POST',
                    'path' => '/v1/templates/{templateUuid}/publish',
                    'summary' => 'Publish a template',
                    'description' => 'Publishes the current draft of a template as a new immutable '
                        .'version and returns that version number. Snaps and previews render the '
                        .'published version, so an edit takes effect only after this call. The call '
                        .'is safe to repeat: a draft with no new edits returns the version which is '
                        .'already live and creates no second version.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['policy:update,Template'],
                    'parameters' => [
                        ['name' => 'templateUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template whose draft you want to publish.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or your account is not allowed to change templates of this company.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 200, 'description' => 'The published version.', 'example' => [
                            'data' => [
                                'id' => '141e8454-8aa1-420a-bd0b-18a85ba949a2',
                                'uuid' => '141e8454-8aa1-420a-bd0b-18a85ba949a2',
                                'published_version' => 2,
                            ],
                            'message' => 'Template published. New snaps and previews now render this version.',
                        ]],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                    ],
                    'notes' => [
                        'BREAKING CHANGE. The response fields moved under a `data` key. Read `data.published_version`, not `published_version`.',
                        '`data.id` is deprecated. It repeats the value of `data.uuid`, which is the name every other template field uses. Read `data.uuid`. `data.id` stays until a major version removes it.',
                    ],
                    'examples' => [
                        'curl' => "curl -X POST https://snapsbrew.com/api/v1/templates/<uuid>/publish \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'templates.destroy',
                    'method' => 'DELETE',
                    'path' => '/v1/templates/{templateIdentifier}',
                    'summary' => 'Delete a template',
                    'description' => 'Deletes a template. The path segment accepts the template '
                        .'UUID or the numeric template ID. GET /v1/templates returns the UUID. '
                        .'Requires the delete-template policy. The delete also removes the snaps, '
                        .'the images, the versions and the forms of the template, and removes the '
                        .'template from its collections. You cannot undo it.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['policy:delete,Template'],
                    'parameters' => [
                        ['name' => 'templateIdentifier', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'Template UUID or numeric template ID, scoped to your company. A value of only digits reads the numeric ID. Every other value reads the UUID.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or your account is not allowed to change templates of this company.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 200, 'description' => 'Template deleted.', 'example' => ['message' => 'Template deleted successfully']],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                    ],
                    'notes' => [
                        'This body carries no `data` key. The other template endpoints hold one, and a delete is the exemption: the template is gone, so there is no resource to return.',
                    ],
                    'examples' => [
                        'curl' => "curl -X DELETE https://snapsbrew.com/api/v1/templates/<uuid> \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
            ],
        ];
    }

    private static function groupEditorDocument(): array
    {
        return [
            'slug' => 'editor-documents',
            'title' => 'Editor documents',
            'tagline' => 'The draft behind the template.',
            'description' => 'An editor document is the v2 draft of a template: its layers, its '
                .'groups and every design-time property the editor shows. A template renders '
                ."from that draft, so a change here changes what the next render draws.\n\n"
                .'Every write is a compare-and-swap. Read the document, keep the `revision` it '
                .'returns, and send that revision back. If the stored revision moved, the write '
                .'returns `409` with the current revision and the current document. Rebuild the '
                .'change on top of them and send the write again. Never resend the same body '
                ."with the returned revision.\n\n"
                .'`PATCH` applies a closed list of operations and changes only the properties '
                .'you name. `PUT` replaces the whole document and drops every property you leave '
                .'out. `GET /v1/guides/editor-document` carries the constraints both accept.',
            'endpoints' => [
                [
                    'id' => 'editor-documents.show',
                    'method' => 'GET',
                    'path' => '/v1/templates/{templateUuid}/document',
                    'summary' => 'Get the editor document',
                    'description' => 'Returns the stored v2 editor document and its revision. A '
                        .'template with no draft returns a null document and revision 0. Read this '
                        .'before every write.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['throttle:editor-document'],
                    'parameters' => [
                        ['name' => 'templateUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'The document and its revision.', 'example' => [
                            'document' => ['version' => 2, 'layers' => [
                                ['key' => 'headline', 'type' => 'text', 'properties' => [['name' => 'fontWeight', 'value' => 700]]],
                            ], 'groups' => []],
                            'revision' => 4,
                        ]],
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or your account is not allowed to read templates.', 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                    ],
                    'notes' => [
                        'The read gate and the write gate name the same people. Every account in your company that can write the document can also read it.',
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/templates/<uuid>/document \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'editor-documents.apply',
                    'method' => 'PATCH',
                    'path' => '/v1/templates/{templateUuid}/document',
                    'summary' => 'Apply editor operations',
                    'description' => 'Applies a closed list of layer operations and saves the result '
                        .'under the next revision. `set_layer_property` takes `layer_key`, `property` '
                        .'and `value`. `set_layer_type` takes `layer_key` and `type`. Every operation '
                        .'in one call applies together, or none of them applies.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['throttle:editor-document'],
                    'parameters' => [
                        ['name' => 'templateUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template.'],
                    ],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'revision', 'type' => 'integer', 'required' => true, 'rules' => 'required|integer|min:0', 'description' => 'The revision the last read returned.'],
                            ['name' => 'operations', 'type' => 'array', 'items' => [
                                'type' => 'object',
                                'required' => ['operation', 'layer_key'],
                                'properties' => [
                                    'operation' => ['type' => 'string', 'enum' => ['set_layer_property', 'set_layer_type']],
                                    'layer_key' => ['type' => 'string'],
                                    'property' => ['type' => 'string', 'description' => 'set_layer_property only.'],
                                    'value' => ['description' => 'set_layer_property only. Any JSON value that the property accepts.'],
                                    'type' => ['type' => 'string', 'description' => 'set_layer_type only.'],
                                ],
                            ], 'required' => true, 'rules' => 'required|array|max:100', 'description' => 'One to 100 operation objects, applied in order.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or your account is not allowed to change templates of this company.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 200, 'description' => 'The saved document and its new revision.', 'example' => [
                            'document' => ['version' => 2, 'layers' => [
                                ['key' => 'headline', 'type' => 'text', 'properties' => [['name' => 'fontWeight', 'value' => 700]]],
                            ], 'groups' => []],
                            'revision' => 5,
                        ]],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                        ['status' => 409, 'description' => 'The stored revision moved. Nothing was saved.', 'schema' => ['$ref' => 'EditorDocumentConflict'], 'example' => [
                            'code' => 'editor_document_conflict',
                            'message' => 'The document changed since you read it, so nothing was saved. Apply your change to `current_document`, then send it again with `current_revision`.',
                            'current_revision' => 5,
                            'current_document' => ['version' => 2, 'layers' => [], 'groups' => []],
                        ]],
                        ['status' => 422, 'description' => 'The body is malformed, or the document rejects an operation.', 'schema' => ['oneOf' => [['$ref' => 'EditorOperationError'], ['$ref' => 'ValidationError']]], 'example' => ['code' => 'editor_operation_layer_not_found', 'message' => 'No layer has this `layer_key`. Read GET /v1/templates/{templateUuid}/document and use the `key` of a layer.']],
                    ],
                    'notes' => [
                        'A rejected operation returns a stable `code`, for example `editor_operation_layer_not_found` or `editor_operation_invalid_value`. Correct the operation and call again.',
                        'A malformed body returns the standard validation shape instead, with a message under `errors.revision` or `errors.operations`.',
                        'The route allows 30 calls a minute for one token and 180 a minute for one company.',
                    ],
                    'examples' => [
                        'curl' => "curl -X PATCH https://snapsbrew.com/api/v1/templates/<uuid>/document \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"revision\":4,\"operations\":[{\"operation\":\"set_layer_property\",\"layer_key\":\"headline\",\"property\":\"fontWeight\",\"value\":700}]}'",
                    ],
                ],
                [
                    'id' => 'editor-documents.update',
                    'method' => 'PUT',
                    'path' => '/v1/templates/{templateUuid}/document',
                    'summary' => 'Replace the editor document',
                    'description' => 'Replaces the whole v2 editor document and saves it under the '
                        .'next revision. Send the document you read, with your change applied to it. '
                        .'Any layer or property you leave out is gone. Prefer `PATCH` unless you '
                        .'rebuild the document.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['throttle:editor-document'],
                    'parameters' => [
                        ['name' => 'templateUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template.'],
                    ],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'revision', 'type' => 'integer', 'required' => true, 'rules' => 'required|integer|min:0', 'description' => 'The revision the last read returned.'],
                            ['name' => 'document', 'type' => 'object', 'required' => true, 'rules' => 'required|array', 'description' => 'The complete v2 document, with `layers` and `groups`.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or your account is not allowed to change templates of this company.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 200, 'description' => 'The saved document and its new revision.', 'example' => [
                            'document' => ['version' => 2, 'layers' => [], 'groups' => []],
                            'revision' => 5,
                        ]],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                        ['status' => 409, 'description' => 'The stored revision moved. Nothing was saved.', 'schema' => ['$ref' => 'EditorDocumentConflict'], 'example' => [
                            'code' => 'editor_document_conflict',
                            'message' => 'The document changed since you read it, so nothing was saved. Apply your change to `current_document`, then send it again with `current_revision`.',
                            'current_revision' => 5,
                            'current_document' => ['version' => 2, 'layers' => [], 'groups' => []],
                        ]],
                        ['status' => 422, 'description' => 'The body is malformed, or the document breaks a limit.', 'example' => ['message' => 'The given data was invalid.', 'errors' => ['document' => ['editor_document_too_large']]]],
                    ],
                    'notes' => [
                        'The document is bounded: 262144 bytes, 1000 nodes, depth 12, and 16384 characters for one string. A document over a limit returns `editor_document_too_large` or `editor_document_budget_exceeded` under `errors.document`.',
                        'The route allows 30 calls a minute for one token and 180 a minute for one company.',
                    ],
                    'examples' => [
                        'curl' => "curl -X PUT https://snapsbrew.com/api/v1/templates/<uuid>/document \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"revision\":4,\"document\":{\"version\":2,\"layers\":[],\"groups\":[]}}'",
                    ],
                ],
                [
                    'id' => 'editor-documents.preflight',
                    'method' => 'GET',
                    'path' => '/v1/templates/{templateUuid}/document/preflight',
                    'summary' => 'Inspect the editor document',
                    'description' => 'Reads the stored document and returns the known draft problems '
                        .'it carries, each with a code, a JSON pointer and a message. An empty '
                        .'`issues` list means the known checks pass. It does not guarantee that the '
                        .'render looks right.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['throttle:editor-document'],
                    'parameters' => [
                        ['name' => 'templateUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'The issues and the revision they were read from.', 'example' => [
                            'issues' => [[
                                'code' => 'image_asset_missing',
                                'path' => '/layers/0/source',
                                'message' => 'Image layers require a pinned asset id.',
                            ]],
                            'revision' => 4,
                        ]],
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or your account is not allowed to read templates.', 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                    ],
                    'notes' => [
                        'Run it after the last write and before you publish the template.',
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/templates/<uuid>/document/preflight \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
            ],
        ];
    }

    private static function groupMedia(): array
    {
        return [
            'slug' => 'media',
            'title' => 'Media',
            'tagline' => 'Your uploaded image library.',
            'description' => 'Upload and list images stored in your account media '
                .'library. Use these as image-layer sources in snaps.',
            'endpoints' => [
                [
                    'id' => 'media.index',
                    'method' => 'GET',
                    'path' => '/v1/media',
                    'summary' => 'List media',
                    'description' => 'Returns your uploaded images, 12 per page, newest first.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => [],
                    'parameters' => [
                        ['name' => 'page', 'in' => 'query', 'type' => 'integer', 'required' => false, 'example' => 1, 'description' => 'Page number for the standard paginator.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'A page of media, as a flat Laravel paginator.', 'schema' => ['$ref' => 'MediaPage'], 'example' => [
                            'current_page' => 1,
                            'data' => [['id' => 12, 'uuid' => '0d4c3a1e-5b2f-4e8a-9c7d-1f2e3a4b5c6d', 'name' => 'logo', 'file_name' => '7b0e4c2a-91d3-4f6e-8a5b-2c1d9e3f4a6b.png', 'mime_type' => 'image/png', 'size' => 20480, 'original_url' => 'https://snapsbrew.com/storage/12/7b0e4c2a-91d3-4f6e-8a5b-2c1d9e3f4a6b.png', 'src' => 'https://snapsbrew.com/storage/12/conversions/7b0e4c2a-91d3-4f6e-8a5b-2c1d9e3f4a6b-image.jpg', 'created_at' => '2026-05-01T12:00:00.000000Z']],
                            'first_page_url' => 'https://snapsbrew.com/api/v1/media?page=1',
                            'from' => 1,
                            'last_page' => 1,
                            'last_page_url' => 'https://snapsbrew.com/api/v1/media?page=1',
                            'links' => [['url' => null, 'label' => '&laquo; Previous', 'active' => false], ['url' => 'https://snapsbrew.com/api/v1/media?page=1', 'label' => '1', 'active' => true], ['url' => null, 'label' => 'Next &raquo;', 'active' => false]],
                            'next_page_url' => null,
                            'path' => 'https://snapsbrew.com/api/v1/media',
                            'per_page' => 12,
                            'prev_page_url' => null,
                            'to' => 1,
                            'total' => 1,
                        ]],
                    ],
                    'notes' => [
                        'The body is a flat Laravel paginator. The page fields sit next to `data`, and there is no `meta` key.',
                        'Each item holds the documented Media fields only. Read `src` for the URL to use as an `image_url` variant.',
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/media \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'media.store',
                    'method' => 'POST',
                    'path' => '/v1/media',
                    'summary' => 'Upload media',
                    'description' => 'Uploads an image into your media library. Send as '
                        .'multipart/form-data. The server stores the file under a random name '
                        .'with the extension of the detected image type. `name` keeps the '
                        .'original name.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => [],
                    'parameters' => [],
                    'body' => [
                        'contentType' => 'multipart/form-data',
                        'fields' => [
                            ['name' => 'file', 'type' => 'file', 'required' => true, 'rules' => 'required, file, mimes:jpg,jpeg,png,gif,webp,bmp, extensions:jpg,jpeg,png,gif,webp,bmp, max:10240', 'description' => 'The image to upload. SVG is not accepted.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 200, 'description' => 'Media uploaded. The body is the media record with no `data` wrapper.', 'schema' => ['$ref' => 'Media'], 'example' => ['id' => 12, 'uuid' => '0d4c3a1e-5b2f-4e8a-9c7d-1f2e3a4b5c6d', 'name' => 'logo', 'file_name' => '7b0e4c2a-91d3-4f6e-8a5b-2c1d9e3f4a6b.png', 'mime_type' => 'image/png', 'size' => 20480, 'original_url' => 'https://snapsbrew.com/storage/12/7b0e4c2a-91d3-4f6e-8a5b-2c1d9e3f4a6b.png', 'src' => 'https://snapsbrew.com/storage/12/conversions/7b0e4c2a-91d3-4f6e-8a5b-2c1d9e3f4a6b-image.jpg', 'created_at' => '2026-05-01T12:00:00.000000Z']],
                        ['status' => 422, 'description' => 'File missing, not a supported image type, or larger than 10 MB.', 'schema' => ['$ref' => 'ValidationError'], 'example' => ['message' => 'The file field must be a file of type: jpg, jpeg, png, gif, webp, bmp.', 'errors' => ['file' => ['The file field must be a file of type: jpg, jpeg, png, gif, webp, bmp.']]]],
                    ],
                    'examples' => [
                        'curl' => "curl -X POST https://snapsbrew.com/api/v1/media \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -F 'file=@logo.png'",
                    ],
                ],
            ],
        ];
    }

    private static function groupScreenshots(): array
    {
        return [
            'slug' => 'screenshots',
            'title' => 'Screenshots',
            'tagline' => 'Capture any page, any size.',
            'description' => 'Render a web page to an image.',
            'endpoints' => [
                [
                    'id' => 'screenshots.index',
                    'method' => 'GET',
                    'path' => '/v1/screenshots',
                    'summary' => 'List screenshots',
                    'description' => 'Returns a cursor-paginated list of your screenshots.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [
                        ['name' => 'cursor', 'in' => 'query', 'type' => 'string', 'required' => false, 'description' => 'Cursor token from meta.next_cursor.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'A page of screenshots. Each item names its id `uuid`.', 'schema' => self::cursorPage('ScreenshotListItem'), 'example' => [
                            'data' => [['uuid' => 'a1b2c3', 'url' => 'https://example.com', 'image_url' => 'https://snapsbrew.com/storage/screenshots/a1b2c3.png', 'width' => 1330, 'height' => 780, 'created_at' => '2026-05-01T12:00:00Z']],
                            'meta' => ['previous_cursor' => null, 'next_cursor' => null],
                        ]],
                        ['status' => 422, 'description' => 'A query parameter failed a rule.', 'schema' => ['$ref' => 'ValidationError'], 'example' => ['message' => 'The cursor must be a string.', 'errors' => ['cursor' => ['The cursor must be a string.']]]],
                    ],
                    'notes' => [
                        'A list item names its id `uuid`. GET /v1/screenshots/{screenshotUuid} and POST /v1/screenshots name the same value `id`.',
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/screenshots \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'screenshots.show',
                    'method' => 'GET',
                    'path' => '/v1/screenshots/{screenshotUuid}',
                    'summary' => 'Get a screenshot',
                    'description' => 'Returns a single screenshot.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [
                        ['name' => 'screenshotUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the screenshot.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'The screenshot. The id field is `id`.', 'schema' => self::wrapped('Screenshot'), 'example' => ['data' => ['id' => 'a1b2c3', 'url' => 'https://example.com', 'image_url' => 'https://snapsbrew.com/storage/screenshots/a1b2c3.png', 'width' => 1330, 'height' => 780, 'created_at' => '2026-05-01T12:00:00Z']]],
                        ['status' => 404, 'description' => 'Screenshot not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Screenshot].']],
                    ],
                    'examples' => [
                        'curl' => "curl https://snapsbrew.com/api/v1/screenshots/<uuid> \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'screenshots.store',
                    'method' => 'POST',
                    'path' => '/v1/screenshots',
                    'summary' => 'Create a screenshot',
                    'description' => 'Captures a web page. Defaults to a 1330x780 viewport '
                        .'when width/height are omitted.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'url', 'type' => 'string', 'required' => true, 'rules' => 'required, url', 'description' => 'Page to capture.'],
                            ['name' => 'width', 'type' => 'integer', 'required' => false, 'rules' => 'integer, min:1, max:'.Template::MAX_DIMENSION, 'description' => 'Viewport width, from 1 to '.Template::MAX_DIMENSION.'. Defaults to 1330.'],
                            ['name' => 'height', 'type' => 'integer', 'required' => false, 'rules' => 'integer, min:1, max:'.Template::MAX_DIMENSION, 'description' => 'Viewport height, from 1 to '.Template::MAX_DIMENSION.'. Defaults to 780.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 200, 'description' => 'Screenshot created. The id field is `id`.', 'schema' => self::wrapped('Screenshot'), 'example' => ['data' => ['id' => 'a1b2c3', 'url' => 'https://example.com', 'image_url' => 'https://snapsbrew.com/storage/screenshots/a1b2c3.png', 'width' => 1330, 'height' => 780, 'created_at' => '2026-05-01T12:00:00Z']]],
                        ['status' => 401, 'description' => 'Missing or invalid token.', 'example' => ['message' => 'Unauthenticated.']],
                        ['status' => 422, 'description' => 'A field failed a rule.', 'schema' => ['$ref' => 'ValidationError'], 'example' => ['message' => 'The width must be at least 1.', 'errors' => ['width' => ['The width must be at least 1.']]]],
                    ],
                    'examples' => [
                        'curl' => "curl -X POST https://snapsbrew.com/api/v1/screenshots \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"url\":\"https://example.com\",\"width\":1330,\"height\":780}'",
                    ],
                ],
            ],
        ];
    }

    private static function groupSnaps(): array
    {
        return [
            'slug' => 'snaps',
            'title' => 'Snaps',
            'tagline' => 'Templates in, finished assets out.',
            'description' => 'A snap renders a template (or every template in a '
                .'collection) into a finished image or PDF, with your content '
                .'substituted into each layer. Render synchronously for an instant '
                .'result, or asynchronously with a webhook for bulk work.',
            'endpoints' => [
                [
                    'id' => 'snaps.store',
                    'method' => 'POST',
                    'path' => '/v1/snaps',
                    'summary' => 'Create a snap',
                    'description' => 'Renders a snap from a single template (`template_id`) '
                        .'or every template in a collection (`collection_id`). Provide '
                        ."exactly one of the two.\n\n"
                        .'Synchronous (default): the response carries the finished '
                        ."`media_url` and returns 201.\n\n"
                        .'Asynchronous (`async: true`): the snap is queued, the response '
                        .'returns 202 immediately, and the result is delivered to '
                        .'`webhook_url`. The webhook body is `{"event": ..., "snap": {...}}`. The event is '
                        .'`snap.completed` when the render succeeds, or `snap.failed` when it fails. '
                        ."A failed snap has `status: failed` and `last_error: render_failed`.\n\n"
                        ."The API always renders the template's **published** version. "
                        .'Unpublished draft edits are not visible to the API until the '
                        .'template is published.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'template_id', 'type' => 'string', 'required' => false, 'rules' => 'required_without:collection_id, prohibits:collection_id, exists templates.uuid scoped to company', 'description' => 'UUID of a single template to render.'],
                            ['name' => 'collection_id', 'type' => 'string', 'required' => false, 'rules' => 'required_without:template_id, prohibits:template_id, exists template_collections.uuid scoped to company', 'description' => 'UUID of a collection; one snap is rendered per template.'],
                            ['name' => 'type', 'type' => 'string', 'required' => false, 'rules' => 'nullable, in:image,pdf', 'description' => 'Output type. Defaults to image.'],
                            ['name' => 'format', 'type' => 'string', 'required' => false, 'rules' => 'sometimes, in:png,jpeg,webp', 'description' => 'Image output format (image type only). Defaults to png.'],
                            ['name' => 'transparent', 'type' => 'boolean', 'required' => false, 'rules' => 'sometimes, boolean; rejected with png-only/webp-only — 422 when combined with format=jpeg', 'description' => 'Render on a transparent background. Valid for png and webp only; jpeg + transparent returns 422.'],
                            ['name' => 'async', 'type' => 'boolean', 'required' => false, 'rules' => 'sometimes, boolean', 'description' => 'Queue the render and return 202.'],
                            ['name' => 'webhook_url', 'type' => 'string', 'required' => false, 'rules' => 'url, required_if:async,true, must be a public http or https address', 'description' => 'Callback URL invoked when an async snap completes. The address must be public. A host that resolves to a private, loopback, link-local or reserved range is rejected with 422. The callback does not follow a redirect. The app checks the address again when it sends the callback, and connects to the address that it checked. If delivery fails, `last_error` holds a code: `webhook_address_refused`, `webhook_redirect_refused`, `webhook_unreachable` or `webhook_http_<status>`.'],
                            ['name' => 'variants', 'type' => 'array', 'items' => ['$ref' => 'Variant'], 'required' => false, 'rules' => 'nullable, array; image_url must be a public http or https URL, background_color must be a CSS color, barcode_content max:1000', 'description' => 'Per-layer content overrides applied to the render. Each item is one Variant object: a layer key and one override.'],
                            ['name' => 'idempotency_key', 'type' => 'string', 'required' => false, 'rules' => 'sometimes, nullable, string, max:191, letters/digits/underscore/hyphen only, prohibits:collection_id', 'description' => 'Send the same key when you retry after a timeout. The retry returns the same snap and does not render it again. Keys are honored for 24 hours and are scoped to your account. Not supported with collection_id.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 201, 'description' => 'Synchronous snap rendered. The body is the snap with no `data` wrapper. A collection request returns an array of snaps.', 'schema' => ['oneOf' => [['$ref' => 'Snap'], ['type' => 'array', 'items' => ['$ref' => 'Snap']]]], 'example' => [
                            'id' => '9f1c2d3e', 'media_url' => 'https://snapsbrew.com/storage/snaps/9f1c2d3e.png', 'width' => 1200, 'height' => 630, 'type' => 'image', 'status' => 'completed', 'is_async' => false, 'webhook_url' => null, 'completed_at' => '2026-05-01T12:00:03Z', 'last_error' => null, 'warnings' => [], 'variants' => [], 'template_id' => '141e8454-8aa1-420a-bd0b-18a85ba949a2', 'template_title' => 'Launch card', 'created_at' => '2026-05-01T12:00:00Z',
                        ]],
                        ['status' => 202, 'description' => 'Asynchronous snap queued. The body is the snap with no `data` wrapper. A collection request returns an array of snaps.', 'schema' => ['oneOf' => [['$ref' => 'Snap'], ['type' => 'array', 'items' => ['$ref' => 'Snap']]]], 'example' => [
                            'id' => '9f1c2d3e', 'media_url' => '', 'width' => 1200, 'height' => 630, 'type' => 'image', 'status' => 'pending', 'is_async' => true, 'webhook_url' => 'https://example.com/hooks/snap', 'completed_at' => null, 'last_error' => null, 'warnings' => [], 'variants' => [], 'template_id' => '141e8454-8aa1-420a-bd0b-18a85ba949a2', 'template_title' => 'Launch card', 'created_at' => '2026-05-01T12:00:00Z',
                        ]],
                        ['status' => 403, 'description' => 'The token lacks the api_requests ability, or a permission check refused the request.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => 'This action is unauthorized.']],
                        ['status' => 404, 'description' => 'The collection holds no templates.', 'schema' => ['$ref' => 'Error'], 'example' => ['message' => "The collection doesn't have any templates."]],
                        ['status' => 409, 'description' => 'The idempotency_key cannot be used. Read `code`: `idempotency_key_in_progress` means another request holds the key, so retry with the same key. `idempotency_key_belongs_to_batch` means a batch owns the key, so send a new key. Nothing was created.', 'schema' => [
                            'type' => 'object',
                            'required' => ['message', 'code'],
                            'properties' => [
                                'message' => ['type' => 'string'],
                                'code' => ['type' => 'string', 'enum' => ['idempotency_key_in_progress', 'idempotency_key_belongs_to_batch']],
                            ],
                        ], 'example' => ['message' => 'A request with this idempotency_key is already in progress. Retry in a moment. Nothing was created and nothing was charged.', 'code' => 'idempotency_key_in_progress']],
                        ['status' => 422, 'description' => 'Validation failed. A `template_id` or `collection_id` that does not exist in your company fails the `exists` rule, so it returns 422 with a message under `errors.template_id` or `errors.collection_id`, not 404.', 'schema' => ['$ref' => 'ValidationError'], 'example' => ['message' => 'The selected template id is invalid.', 'errors' => ['template_id' => ['The selected template id is invalid.']]]],
                        ['status' => 500, 'description' => 'An unexpected error stopped the request. The body holds a fixed message and the code `internal_error`, and no detail of the cause. The server logs the cause.', 'schema' => ['$ref' => 'InternalError'], 'example' => ['message' => 'The server could not create the snap. Try again later. If the error continues, contact support and give the X-Request-Uuid header of this response.', 'code' => 'internal_error']],
                    ],
                    'notes' => [
                        'Every response carries an `X-Request-Uuid` header. Quote it in support tickets.',
                        'A collection request returns an array of snap objects, one per template.',
                        'A response to a repeated `idempotency_key` carries an `X-Idempotent-Replay: true` header and renders nothing.',
                        '`webhook_url` must be a public address. A host that resolves to a private, loopback, link-local or reserved range is rejected with 422.',
                        self::WEBHOOK_SIGNATURE_NOTE,
                        'An `idempotency_key` is honored for 24 hours. After that window a retry is a new request and renders a new snap.',
                    ],
                    'examples' => [
                        'curl' => "curl -X POST https://snapsbrew.com/api/v1/snaps \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"template_id\":\"<uuid>\",\"type\":\"image\",\"variants\":[{\"layer\":\"review_title\",\"text\":\"Hello world\"}]}'",
                        'async curl' => "curl -X POST https://snapsbrew.com/api/v1/snaps \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"template_id\":\"<uuid>\",\"async\":true,\"webhook_url\":\"https://example.com/hooks/snap\"}'",
                    ],
                ],
            ],
        ];
    }

    private static function groupSnapBatches(): array
    {
        $terminalNote = 'A batch holds one of five statuses. `pending` and `processing` mean '
            .'it is still running. The three terminal statuses are `completed`, `failed` and '
            .'`canceled`, and a batch never leaves one. `completed` means the batch ran to the '
            .'end and at least one item rendered, so read the `failed` count as well as the '
            .'status. `failed` means zero items rendered. At every terminal status '
            .'`completed + failed + canceled` equals `total_rows`.';

        return [
            'slug' => 'snap-batches',
            'title' => 'Snap batches',
            'tagline' => 'Up to 100 snaps from one call.',
            'description' => 'A batch renders up to 100 snaps from ONE template, with a '
                .'different set of variants per item. The call is always asynchronous: it '
                .'returns 202 with a batch id, and the items render in the background. Poll '
                .'the batch to read progress and to collect the image URLs, and cancel it to '
                .'stop the items that have not started. One webhook is posted when the batch '
                .'finishes.',
            'endpoints' => [
                [
                    'id' => 'snap-batches.store',
                    'method' => 'POST',
                    'path' => '/v1/snaps/batches',
                    'summary' => 'Create a batch of snaps',
                    'description' => 'Renders up to 100 snaps from one template. Every item '
                        .'carries its own `variants` and an optional `identifier` you use to '
                        ."recognize it in the result.\n\n"
                        ."The response is always 202. Each item renders in the background.\n\n"
                        .'`type`, `format` and `transparent` apply to every item in the batch. '
                        ."An item that needs a different format belongs in a different batch.\n\n"
                        ."The API always renders the template's **published** version.",
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'template_id', 'type' => 'string', 'required' => true, 'rules' => 'required, exists templates.uuid scoped to company', 'description' => 'UUID of the template every item renders from.'],
                            ['name' => 'items', 'type' => 'array', 'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'identifier' => ['type' => 'string', 'nullable' => true, 'maxLength' => 191, 'description' => 'Your own name for this item.'],
                                    'variants' => ['type' => 'array', 'items' => ['$ref' => 'Variant'], 'description' => 'Per-layer content overrides for this item.'],
                                ],
                            ], 'required' => true, 'rules' => 'required, array, min:1, max:100', 'description' => 'One object per snap. Over 100 entries returns 422 and creates nothing.'],
                            ['name' => 'items[].variants', 'type' => 'array', 'required' => false, 'rules' => 'each image_url must be a public http or https address', 'description' => 'Per-layer content overrides for this item.'],
                            ['name' => 'items[].identifier', 'type' => 'string', 'required' => false, 'rules' => 'sometimes, nullable, string, max:191', 'description' => 'Your own name for this item. Defaults to item_1, item_2, and so on.'],
                            ['name' => 'title', 'type' => 'string', 'required' => false, 'rules' => 'sometimes, string, max:255', 'description' => 'A name for the batch, shown in the account. Defaults to "API batch".'],
                            ['name' => 'webhook_url', 'type' => 'string', 'required' => false, 'rules' => 'nullable, url, max:2048, must be a public http or https address', 'description' => 'ONE request is posted to this address when the batch finishes. A host that resolves to a private, loopback, link-local or reserved range is rejected with 422.'],
                            ['name' => 'type', 'type' => 'string', 'required' => false, 'rules' => 'sometimes, in:image,pdf', 'description' => 'Output type for every item. Defaults to image.'],
                            ['name' => 'format', 'type' => 'string', 'required' => false, 'rules' => 'sometimes, in:png,jpeg,webp', 'description' => 'Image output format for every item. Defaults to png.'],
                            ['name' => 'transparent', 'type' => 'boolean', 'required' => false, 'rules' => 'sometimes, boolean; 422 when combined with format=jpeg', 'description' => 'Render every item on a transparent background. Valid for png and webp only.'],
                            ['name' => 'idempotency_key', 'type' => 'string', 'required' => false, 'rules' => 'sometimes, nullable, string, max:191, letters/digits/underscore/hyphen only', 'description' => 'Send the same key when you retry after a timeout. The retry returns the same batch and starts no second one. Keys are honored for 24 hours and are scoped to your account. A key already used by POST /v1/snaps returns 409.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 202, 'description' => 'Batch accepted and queued. The body is the batch with no `data` wrapper.', 'schema' => ['$ref' => 'Batch'], 'example' => [
                            'uuid' => '7c1f2a9e-0b44-4a1e-9f2b-5d3c8e1a7b60', 'title' => 'October launch', 'status' => 'processing',
                            'total_rows' => 3, 'processed_rows' => 0, 'completed' => 0, 'pending' => 3, 'failed' => 0, 'canceled' => 0,
                            'status_url' => 'https://snapsbrew.com/api/v1/snaps/batches/7c1f2a9e-0b44-4a1e-9f2b-5d3c8e1a7b60',
                        ]],
                        ['status' => 409, 'description' => 'The idempotency_key cannot be used. Read `code`: `idempotency_key_in_progress` means another request holds the key, so retry with the same key. `idempotency_key_belongs_to_snap` means a single snap owns the key, so send a new key. Nothing was created.', 'schema' => [
                            'type' => 'object',
                            'required' => ['message', 'code'],
                            'properties' => [
                                'message' => ['type' => 'string'],
                                'code' => ['type' => 'string', 'enum' => ['idempotency_key_in_progress', 'idempotency_key_belongs_to_snap']],
                            ],
                        ], 'example' => ['message' => 'This idempotency_key already belongs to a single snap. Use a new key for a batch.', 'code' => 'idempotency_key_belongs_to_snap']],
                        ['status' => 422, 'description' => 'Validation failed. A `template_id` that does not exist in your company returns 422 under `errors.template_id`.', 'schema' => ['$ref' => 'ValidationError'], 'example' => ['message' => 'A batch accepts at most 100 items. Split the request into smaller batches.', 'errors' => ['items' => ['A batch accepts at most 100 items. Split the request into smaller batches.']]]],
                        ['status' => 500, 'description' => 'An unexpected error stopped the request. The body holds a fixed message and the code `internal_error`, and no detail of the cause. The server logs the cause.', 'schema' => ['$ref' => 'InternalError'], 'example' => ['message' => 'The server could not create the batch. Try again later. If the error continues, contact support and give the X-Request-Uuid header of this response.', 'code' => 'internal_error']],
                    ],
                    'notes' => [
                        'Over 100 items returns 422 on `errors.items` and creates nothing. The request is never truncated in silence.',
                        'Exactly one webhook is posted, when the batch finishes. There is no per-item webhook on this endpoint.',
                        self::WEBHOOK_SIGNATURE_NOTE,
                        '`webhook_url` must be a public address. A host that resolves to a private, loopback, link-local or reserved range is rejected with 422.',
                        'A response to a repeated `idempotency_key` carries an `X-Idempotent-Replay: true` header.',
                        'Every response carries an `X-Request-Uuid` header. Quote it in support tickets.',
                        $terminalNote,
                    ],
                    'examples' => [
                        'curl' => "curl -X POST https://snapsbrew.com/api/v1/snaps/batches \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"template_id\":\"<uuid>\",\"title\":\"October launch\",\"items\":[{\"identifier\":\"row-1\",\"variants\":[{\"layer\":\"headline\",\"text\":\"Hello\"}]},{\"identifier\":\"row-2\",\"variants\":[{\"layer\":\"headline\",\"text\":\"World\"}]}]}'",
                    ],
                ],
                [
                    'id' => 'snap-batches.show',
                    'method' => 'GET',
                    'path' => '/v1/snaps/batches/{batchUuid}',
                    'summary' => 'Read a batch',
                    'description' => 'Returns the counts and every item with its status and '
                        .'image URL. Poll this about every 5 seconds and stop when `status` is '
                        .'`completed`, `failed` or `canceled`.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [
                        ['name' => 'batchUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'The batch id returned by POST /v1/snaps/batches.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'The batch and its items, with no `data` wrapper.', 'schema' => ['$ref' => 'Batch'], 'example' => [
                            'uuid' => '7c1f2a9e-0b44-4a1e-9f2b-5d3c8e1a7b60', 'title' => 'October launch', 'status' => 'completed',
                            'total_rows' => 2, 'processed_rows' => 2, 'completed' => 1, 'pending' => 0, 'failed' => 1, 'canceled' => 0,
                            'status_url' => 'https://snapsbrew.com/api/v1/snaps/batches/7c1f2a9e-0b44-4a1e-9f2b-5d3c8e1a7b60',
                            'items' => [
                                ['id' => '9f1c2d3e', 'identifier' => 'row-1', 'status' => 'completed', 'media_url' => 'https://snapsbrew.com/storage/snaps/9f1c2d3e.png', 'last_error' => null],
                                ['id' => '4b8a7c6d', 'identifier' => 'row-2', 'status' => 'failed', 'media_url' => null, 'last_error' => 'render_failed'],
                            ],
                        ]],
                        ['status' => 404, 'description' => 'No batch with that id exists in your account.', 'example' => ['message' => 'No batch with that id exists in your account.']],
                    ],
                    'notes' => [
                        $terminalNote,
                        '`media_url` is the image URL on an item with status `completed`, and null on each other item. The snap object of POST /v1/snaps uses an empty string for the same state.',
                        '`last_error` on a failed item holds the fixed code `render_failed`. It never holds the message of the renderer.',
                        'A batch id that belongs to another account returns 404, the same as an id that does not exist.',
                    ],
                    'examples' => [
                        'curl' => "curl 'https://snapsbrew.com/api/v1/snaps/batches/<batch-uuid>' \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'snap-batches.cancel',
                    'method' => 'POST',
                    'path' => '/v1/snaps/batches/{batchUuid}/cancel',
                    'summary' => 'Cancel a batch',
                    'description' => "Stops a batch that is still running.\n\n"
                        .'An item that already rendered keeps its image. '
                        .'An item that had not started is marked `canceled` and never renders. '
                        ."An item that is rendering at that moment can still finish.\n\n"
                        .'This cannot be undone. A canceled batch cannot be resumed. Start a '
                        .'new batch for the items you still want.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [
                        ['name' => 'batchUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'The batch id returned by POST /v1/snaps/batches.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'The batch after the cancel, with its items and no `data` wrapper.', 'schema' => ['$ref' => 'Batch'], 'example' => [
                            'uuid' => '7c1f2a9e-0b44-4a1e-9f2b-5d3c8e1a7b60', 'title' => 'October launch', 'status' => 'canceled',
                            'total_rows' => 3, 'processed_rows' => 3, 'completed' => 1, 'pending' => 0, 'failed' => 0, 'canceled' => 2,
                            'status_url' => 'https://snapsbrew.com/api/v1/snaps/batches/7c1f2a9e-0b44-4a1e-9f2b-5d3c8e1a7b60',
                            'items' => [
                                ['id' => '9f1c2d3e', 'identifier' => 'row-1', 'status' => 'completed', 'media_url' => 'https://snapsbrew.com/storage/snaps/9f1c2d3e.png', 'last_error' => null],
                                ['id' => '4b8a7c6d', 'identifier' => 'row-2', 'status' => 'canceled', 'media_url' => null, 'last_error' => null],
                                ['id' => '2e5f9a1b', 'identifier' => 'row-3', 'status' => 'canceled', 'media_url' => null, 'last_error' => null],
                            ],
                        ]],
                        ['status' => 404, 'description' => 'No batch with that id exists in your account.', 'example' => ['message' => 'No batch with that id exists in your account.']],
                    ],
                    'notes' => [
                        'Canceling twice is safe. A batch that already holds a terminal status is returned unchanged.',
                        'One webhook is posted, carrying `event: snap-import.canceled`.',
                        'An item that is rendering when the cancel lands can still complete. The counts in the response are the truth at that moment.',
                        'A batch id that belongs to another account returns 404, the same as an id that does not exist.',
                    ],
                    'examples' => [
                        'curl' => "curl -X POST 'https://snapsbrew.com/api/v1/snaps/batches/<batch-uuid>/cancel' \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
            ],
        ];
    }

    private static function groupPreview(): array
    {
        $variantsParam = ['name' => 'variants', 'in' => 'query', 'type' => 'array', 'schema' => ['type' => 'array', 'items' => ['$ref' => 'Variant']], 'style' => 'deepObject', 'explode' => true, 'required' => false, 'description' => 'Per-layer overrides in PHP query syntax, for example variants[0][layer]=headline&variants[0][text]=Hello. Each item targets a layer key with one of text, image_url (public URL), background_color, qr_content, barcode_content, or hidden.'];
        $html = ['status' => 200, 'description' => 'Watermarked HTML preview.', 'contentType' => 'text/html', 'schema' => ['type' => 'string'], 'example' => '<!DOCTYPE html>…watermarked preview…</html>'];
        $tooMany = ['status' => 429, 'description' => 'Rate limit exceeded. A preview allows 10 requests per minute for one user, inside the 60 per minute of the api group.', 'example' => ['message' => 'Too Many Attempts.']];

        return [
            'slug' => 'preview',
            'title' => 'Preview',
            'tagline' => 'See it before the final render.',
            'description' => 'Preview renders a template (optionally with variants) into a '
                .'WATERMARKED, self-contained HTML document without creating a snap. The '
                .'response is the rendered HTML — nothing is rasterized and nothing is '
                .'persisted. Use it to iterate, then call POST /v1/snaps to produce the '
                .'final, un-watermarked snap image with the same variants. A preview allows '
                .'10 requests per minute for one user, in addition to the 60 per minute of the api group.',
            'endpoints' => [
                [
                    'id' => 'preview.template',
                    'method' => 'GET',
                    'path' => '/v1/templates/{templateUuid}/preview',
                    'summary' => 'Preview a template',
                    'description' => 'Renders a watermarked preview of a template using '
                        .'its default layer content, or with `variants` applied. Returns '
                        .'a self-contained HTML document (text/html).',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['throttle:preview'],
                    'parameters' => [
                        ['name' => 'templateUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template to preview.'],
                        $variantsParam,
                    ],
                    'body' => null,
                    'responses' => [
                        $html,
                        ['status' => 404, 'description' => 'Template not found in your account.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                        ['status' => 422, 'description' => 'Validation failed, for example an image_url that is not public.', 'example' => ['message' => 'The variants.0.image_url must be a public http or https URL and may not point to an internal or reserved address.', 'errors' => ['variants.0.image_url' => ['The variants.0.image_url must be a public http or https URL and may not point to an internal or reserved address.']]]],
                        $tooMany,
                    ],
                    'notes' => [
                        'The preview is watermarked HTML, not a usable final asset.',
                        'No snap is stored.',
                    ],
                    'examples' => [
                        'curl' => "curl 'https://snapsbrew.com/api/v1/templates/<uuid>/preview' \\\n  -H 'Authorization: Bearer <your-token>' \\\n  --output preview.html",
                    ],
                ],
                [
                    'id' => 'preview.snap',
                    'method' => 'GET',
                    'path' => '/v1/snaps/preview',
                    'summary' => 'Preview a snap',
                    'description' => 'Renders a watermarked preview of the snap you intend '
                        .'to create — a template (`template_id`) plus `variants`. Returns '
                        .'a self-contained HTML document (text/html). Pass the same '
                        .'template_id and variants to POST /v1/snaps to create the final snap.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['throttle:preview'],
                    'parameters' => [
                        ['name' => 'template_id', 'in' => 'query', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template to render.'],
                        $variantsParam,
                    ],
                    'body' => null,
                    'responses' => [
                        $html,
                        ['status' => 404, 'description' => 'Template not found in your account.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                        ['status' => 422, 'description' => 'Validation failed.', 'example' => ['message' => 'The template id field is required.', 'errors' => ['template_id' => ['The template id field is required.']]]],
                        $tooMany,
                    ],
                    'notes' => [
                        'The preview is watermarked HTML, not a usable final asset.',
                        'No snap is stored.',
                    ],
                    'examples' => [
                        'curl' => "curl 'https://snapsbrew.com/api/v1/snaps/preview?template_id=<uuid>&variants[0][layer]=headline&variants[0][text]=Hello' \\\n  -H 'Authorization: Bearer <your-token>' \\\n  --output preview.html",
                    ],
                ],
            ],
        ];
    }

    private static function groupSignedUrls(): array
    {
        return [
            'slug' => 'signed-urls',
            'title' => 'Signed URLs',
            'tagline' => 'An image URL you can put in an img tag.',
            'description' => 'A signed URL renders a template from a plain `<img src>` or an `og:image` '
                .'tag, with no API call and no bearer token. Your server builds the URL and signs it '
                ."with a signed URL key from the Developers tab of the account page.\n\n"
                .'The signing key is a secret. Sign only on a server. Never sign in a browser. The '
                .'guide at /documentation/guides/signed-urls has code in PHP, JavaScript, Python and Ruby.',
            'endpoints' => [
                [
                    'id' => 'signed-urls.image',
                    'method' => 'GET',
                    'path' => '/signed-url/image.jpg',
                    'base' => 'site',
                    'summary' => 'Render a signed URL',
                    'description' => 'Renders the published version of a template with the variants in the URL, '
                        ."then redirects to the image. A repeated URL redirects to the cached image.\n\n"
                        ."To sign a URL:\n"
                        ."1. Take every query parameter except `signature`.\n"
                        ."2. Sort the parameters by key in byte order.\n"
                        ."3. Join them as `key=value` with `&`. Use the raw values, not the URL-encoded values.\n"
                        ."4. Compute the HMAC-SHA256 of that string with your signing key. Use the lowercase hex digest.\n"
                        .'5. Add the digest as `signature`. URL-encode every value in the final URL.',
                    'auth' => 'signature',
                    'abilities' => [],
                    'middleware' => ['signedUrlMiddleware'],
                    'parameters' => [
                        ['name' => 'template_id', 'in' => 'query', 'type' => 'string', 'required' => true, 'example' => '141e8454-8aa1-420a-bd0b-18a85ba949a2', 'description' => 'UUID of the template. The signing key must belong to an active user of the company that owns the template.'],
                        ['name' => 'variants', 'in' => 'query', 'type' => 'string', 'required' => false, 'example' => 'W3sibGF5ZXIiOiJoZWFkbGluZSIsInRleHQiOiJIZWxsbyJ9XQ==', 'description' => 'The JSON array of Variant objects, encoded as standard base64 (RFC 4648 section 4, with `+`, `/` and `=` padding). Do not use base64url: the server decodes with PHP base64_decode. Sign the base64 value, then URL-encode it in the URL.'],
                        ['name' => 'format', 'in' => 'query', 'type' => 'string', 'schema' => ['type' => 'string', 'enum' => ['png', 'jpeg', 'webp']], 'required' => false, 'example' => 'png', 'description' => 'Image format. Defaults to png. Any other value renders png. The value is part of the signature.'],
                        ['name' => 'transparent', 'in' => 'query', 'type' => 'string', 'schema' => ['type' => 'string', 'enum' => ['1', '0', 'true', 'false']], 'required' => false, 'example' => '0', 'description' => 'Set to 1 or true to render on a transparent background. Valid for png and webp. With format=jpeg it returns 400. The value is part of the signature.'],
                        ['name' => 'signature', 'in' => 'query', 'type' => 'string', 'schema' => ['type' => 'string', 'pattern' => '^[0-9a-f]{64}$'], 'required' => true, 'example' => str_repeat('0123456789abcdef', 4), 'description' => 'Lowercase hex HMAC-SHA256 of the canonical string. See the description for the algorithm.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 302, 'description' => 'The image rendered, or a cached image exists. The `Location` header holds the image URL.', 'contentType' => null, 'headers' => [
                            'Location' => ['description' => 'URL of the rendered image.', 'schema' => ['type' => 'string']],
                        ]],
                        ['status' => 200, 'description' => 'The default placeholder image, with status 200. The server returns it when the signature is missing, when the signature matches no active signing key of the template owner, when the template does not exist, and when the template owner is deactivated. A bad signature does not return an error status.', 'contentType' => 'image/png', 'schema' => ['type' => 'string', 'format' => 'binary']],
                        ['status' => 400, 'description' => 'The decoded variants fail the variant rules, the URL asks for format=jpeg with transparent, or the URL has a reserved parameter (`The signed URL has a reserved parameter.`).', 'contentType' => null],
                        ['status' => 429, 'description' => 'Too many requests from one IP address in one minute. Wait the number of seconds in `Retry-After`.', 'contentType' => null],
                    ],
                    'notes' => [
                        'This route is on the site root, not under /api. The full URL is `https://snapsbrew.com/signed-url/image.jpg`.',
                        'The signed string holds every query parameter except `signature`, including a parameter that the server does not read. Do not send `identifier` or `api_log_uuid`, because the server reserves both names.',
                        'A signed URL render counts in the usage log of the template owner.',
                    ],
                    'examples' => [
                        'curl' => "curl -L 'https://snapsbrew.com/signed-url/image.jpg?template_id=<uuid>&variants=<url-encoded-base64>&signature=<hex>' \\\n  --output image.png",
                    ],
                ],
            ],
        ];
    }

    private static function groupTags(): array
    {
        return [
            'slug' => 'tags',
            'title' => 'Tags',
            'tagline' => 'Label and group your templates.',
            'description' => 'Tags organise templates. Create them, search them, and '
                .'attach them to templates. All tags are scoped to your company.',
            'endpoints' => [
                [
                    'id' => 'tags.index',
                    'method' => 'GET',
                    'path' => '/v1/tags',
                    'summary' => 'List tags',
                    'description' => 'Returns up to 15 tags, ordered by name, optionally '
                        .'filtered by a search term.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [
                        ['name' => 'search', 'in' => 'query', 'type' => 'string', 'required' => false, 'description' => 'Case-insensitive substring match on tag name.'],
                    ],
                    'body' => null,
                    'responses' => [
                        ['status' => 200, 'description' => 'Matching tags.', 'example' => ['data' => [['id' => 1, 'name' => 'Launch', 'slug' => 'launch']]]],
                    ],
                    'examples' => [
                        'curl' => "curl 'https://snapsbrew.com/api/v1/tags?search=launch' \\\n  -H 'Authorization: Bearer <your-token>'",
                    ],
                ],
                [
                    'id' => 'tags.store',
                    'method' => 'POST',
                    'path' => '/v1/tags',
                    'summary' => 'Create a tag',
                    'description' => 'Creates a tag. The slug is generated from the name; '
                        .'collisions get a numeric suffix.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'name', 'type' => 'string', 'required' => true, 'rules' => 'required, string, max:255', 'description' => 'Display name of the tag.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 201, 'description' => 'Tag created.', 'example' => ['data' => ['id' => 1, 'name' => 'Launch', 'slug' => 'launch']]],
                        ['status' => 422, 'description' => 'Validation failed.', 'example' => ['message' => 'The name field is required.', 'errors' => ['name' => ['The name field is required.']]]],
                    ],
                    'examples' => [
                        'curl' => "curl -X POST https://snapsbrew.com/api/v1/tags \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"name\":\"Launch\"}'",
                    ],
                ],
                [
                    'id' => 'tags.template.store',
                    'method' => 'POST',
                    'path' => '/v1/templates/{templateUuid}/tags',
                    'summary' => 'Sync template tags',
                    'description' => 'Replaces all tags on a template with the supplied set. '
                        .'Send an empty array to clear every tag.',
                    'auth' => 'bearer',
                    'abilities' => ['api_requests'],
                    'middleware' => ['logApiRequest'],
                    'parameters' => [
                        ['name' => 'templateUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the template.'],
                    ],
                    'body' => [
                        'contentType' => 'application/json',
                        'fields' => [
                            ['name' => 'tag_ids', 'type' => 'integer[]', 'required' => false, 'rules' => 'array', 'description' => 'Tag IDs to attach. Replaces the existing set.'],
                            ['name' => 'tag_ids.*', 'type' => 'integer', 'required' => false, 'rules' => 'integer, exists tags.id scoped to company', 'description' => 'Each ID must belong to your company.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 200, 'description' => 'Tags synced.', 'example' => ['message' => 'Tags updated successfully.', 'tags' => [['id' => 1, 'name' => 'Launch', 'slug' => 'launch']]]],
                        ['status' => 404, 'description' => 'Template not found in your company.', 'example' => ['message' => 'No query results for model [App\\Models\\Template].']],
                        ['status' => 422, 'description' => 'A tag ID is invalid or not yours.', 'example' => ['message' => 'The selected tag_ids.0 is invalid.', 'errors' => ['tag_ids.0' => ['The selected tag_ids.0 is invalid.']]]],
                    ],
                    'examples' => [
                        'curl' => "curl -X POST https://snapsbrew.com/api/v1/templates/<uuid>/tags \\\n  -H 'Authorization: Bearer <your-token>' \\\n  -H 'Content-Type: application/json' \\\n  -d '{\"tag_ids\":[1,2]}'",
                    ],
                ],
            ],
        ];
    }

    private static function groupEmbeddedForms(): array
    {
        return [
            'slug' => 'embedded-forms',
            'title' => 'Embedded Forms',
            'tagline' => 'Let anyone render without a key.',
            'description' => 'Embedded forms render snaps from a signed URL — no API '
                .'token. The URL must be a Laravel signed URL; an invalid signature '
                .'returns 404. The render counts in the usage log of the form owner.',
            'endpoints' => [
                [
                    'id' => 'embedded-forms.store',
                    'method' => 'POST',
                    'path' => '/v1/embedded-forms/{formUuid}',
                    'summary' => 'Submit an embedded form',
                    'description' => 'Renders a snap from a configured form. Template forms '
                        ."return one snap; collection forms return an array of snaps.\n\n"
                        .'Layer keys: template forms use the plain layer key '
                        .'(`headline`); collection forms use `{templateUuid}__{layerKey}`. '
                        .'Only layers the form was configured with are accepted; the '
                        .'rest are dropped silently.',
                    'auth' => 'signature',
                    'abilities' => [],
                    'middleware' => ['embeddedFormMiddleware', 'valid signature required'],
                    'parameters' => [
                        ['name' => 'formUuid', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => 'UUID of the form.'],
                        ['name' => 'signature', 'in' => 'query', 'type' => 'string', 'required' => true, 'description' => 'Laravel URL signature. The whole URL must be signed.'],
                    ],
                    'body' => [
                        'contentType' => 'multipart/form-data',
                        'fields' => [
                            ['name' => 'layers', 'type' => 'object', 'required' => false, 'rules' => 'keys matched against the form configuration; an image layer takes a jpg, png, gif, webp or bmp file of 10 MB or less; a color takes a hex, rgb(a), hsl(a) or named color', 'description' => 'Layer overrides. Value keys by layer type: image -> image_url (file), text -> text, empty -> background_color, qr_code -> qr_content, barcode -> barcode_content.'],
                        ],
                    ],
                    'responses' => [
                        ['status' => 201, 'description' => 'Snap rendered (template form). The body is the snap with no `data` wrapper.', 'schema' => ['$ref' => 'Snap'], 'example' => ['id' => '9f1c2d3e', 'media_url' => 'https://snapsbrew.com/storage/snaps/9f1c2d3e.png', 'width' => 1200, 'height' => 630, 'type' => 'image', 'status' => 'completed', 'is_async' => false, 'webhook_url' => null, 'completed_at' => '2026-05-01T12:00:03Z', 'last_error' => null, 'warnings' => [], 'variants' => [], 'template_id' => '141e8454', 'template_title' => 'Launch card', 'created_at' => '2026-05-01T12:00:00Z']],
                        ['status' => 404, 'description' => 'The form does not exist, or the signature is not valid. A bad signature returns an empty `message`.', 'example' => ['message' => 'No query results for model [App\\Models\\Form].']],
                        ['status' => 422, 'description' => 'Collection form has no templates configured, or a layer value fails validation.', 'schema' => ['oneOf' => [['$ref' => 'Error'], ['$ref' => 'ValidationError']]], 'example' => ['message' => 'The form does not include any templates.']],
                        ['status' => 429, 'description' => 'Too many submissions from one IP address or for one form. Wait the number of seconds in `Retry-After`.', 'schema' => [
                            'type' => 'object',
                            'required' => ['message', 'code'],
                            'properties' => ['message' => ['type' => 'string'], 'code' => ['type' => 'string', 'enum' => ['rate_limited']]],
                        ], 'example' => ['message' => 'Too many submissions. Wait one minute, then send the form again.', 'code' => 'rate_limited']],
                    ],
                    'notes' => [
                        'Collection forms return a bare array of snap objects, not a `data`-wrapped object.',
                        'The route limits the submissions for each IP address and for each form, each minute.',
                    ],
                    'examples' => [
                        'curl' => "curl -X POST 'https://snapsbrew.com/api/v1/embedded-forms/<formUuid>?signature=<sig>' \\\n  -F 'layers[headline]=Summer sale' \\\n  -F 'layers[logo]=@logo.png'",
                    ],
                ],
            ],
        ];
    }
}

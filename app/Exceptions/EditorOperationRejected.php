<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * The operation applier rejected an operation. The message is a fixed code,
 * for example `editor_operation_layer_not_found`, so the caller can read it.
 *
 * Catch this class, not InvalidArgumentException. Other code throws
 * InvalidArgumentException with internal details in its message.
 */
final class EditorOperationRejected extends InvalidArgumentException
{
    /**
     * The REST message for each code. The MCP tools have their own text in
     * `App\Mcp\ToolError`, because that text names tools, not routes.
     */
    private const MESSAGES = [
        'editor_operation_invalid_request' => 'Send `operations` as a non-empty list. Each operation needs a string `operation` and a string `layer_key`.',
        'editor_operation_unknown_type' => 'The `operation` value is not known. Use `set_layer_property` or `set_layer_type`.',
        'editor_operation_document_missing' => 'This template has no v2 editor document. Send the full document with PUT /v1/templates/{templateUuid}/document first.',
        'editor_operation_document_invalid' => 'The stored document has a malformed `layers` list or a malformed `properties` list. Read the document, correct it, and send it with PUT /v1/templates/{templateUuid}/document.',
        'editor_operation_layer_not_found' => 'No layer has this `layer_key`. Read GET /v1/templates/{templateUuid}/document and use the `key` of a layer.',
        'editor_operation_layer_not_unique' => 'More than one layer has this `layer_key`. Give each layer a unique `key` with PUT /v1/templates/{templateUuid}/document.',
        'editor_operation_invalid_property' => 'The property is not in the contract, or the operation has no `value`. GET /v1/guides/editor-document lists each property.',
        'editor_operation_invalid_value' => 'The value does not match the type or the limits of the property. GET /v1/guides/editor-document lists the constraints.',
        'editor_operation_invalid_svg' => 'The `inlineSvg` value is not a safe SVG of an allowed size. GET /v1/guides/editor-document lists the limits.',
        'editor_operation_invalid_layer_type' => 'The layer type is not allowed. Use `text`, `empty`, `qr_code`, `barcode` or `shape`.',
        'editor_operation_shape_requires_svg' => 'A layer becomes a `shape` only when it has a valid `inlineSvg` property. Set `inlineSvg` first, in the same request or before it.',
    ];

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    public static function messageFor(string $code): string
    {
        return self::MESSAGES[$code]
            ?? 'The editor document refused the operation. GET /v1/guides/editor-document describes each operation.';
    }
}

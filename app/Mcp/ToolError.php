<?php

namespace App\Mcp;

use App\Services\CreativePackageClaimException;
use Laravel\Mcp\Response;

/**
 * The one definition of every error an MCP tool returns.
 *
 * An agent reads an error to decide what to call next. Prose does not carry
 * that decision: "No template with id `x` exists in your account" tells a model
 * what went wrong and leaves it to guess the recovery. Each method here returns
 * one JSON object with four fields:
 *
 *   code      A stable identifier. Prose can be reworded. This cannot.
 *   message   What went wrong, for the human the agent reports to.
 *   fix       The next call to make, in the imperative.
 *   retryable Whether the identical call can succeed later. A key that
 *             another request holds right now is true, and so is a package
 *             claim that a full active slot or an empty balance refused.
 *             Every other error needs a different input, or a human, so a
 *             retry loop must stop.
 *   details   The values the agent sent, when they help it build the next call.
 *
 * Every error lives here and not at the call site, because seven tools return
 * "template not found" and one reworded copy is a contract break the tests
 * cannot see. `McpToolErrorTest` reads the tool names out of these strings and
 * fails when one names a tool the server does not register.
 */
final class ToolError
{
    /**
     * Stable v1 package errors. One builder, `creativePackage()`, reads this
     * registry by code. `CreativePackageClaimException` reads `retryable` and
     * `details`, and the builder adds `fix`.
     */
    public const CREATIVE_PACKAGE_ERRORS = [
        'creative_package_invalid_request' => ['retryable' => false, 'details' => ['fields'],
            'fix' => 'Correct the fields named in `details.fields`. Then send the call again.'],
        'creative_package_source_not_found' => ['retryable' => false, 'details' => ['item_index', 'client_creative_id'],
            'fix' => 'Call `list-templates` to read the template ids in your account. '
                .'Then call `export-creative-package` again with a `template_id` and a published `template_version` from that account.'],
        'creative_package_source_invalid' => ['retryable' => false, 'details' => ['issues'],
            'fix' => 'Read `details.issues`. Call `preview-template` to check the template version. '
                .'Then call `export-creative-package` again with a version that renders.'],
        'creative_package_idempotency_conflict' => ['retryable' => false, 'details' => ['idempotency_key', 'package_id'],
            'fix' => 'Send this export again with a new `idempotency_key`. '
                .'To read the package that key already holds, call `get-creative-package` with `details.package_id`.'],
        'creative_package_rate_limited' => ['retryable' => true, 'details' => ['poll_after_seconds'],
            'fix' => 'Wait `details.poll_after_seconds` seconds. Then send the same call again with the same `idempotency_key`.'],
        'creative_package_entitlement_required' => ['retryable' => false, 'details' => [],
            'fix' => 'You cannot correct this from a tool. Call `whoami` to read the account. '
                .'Ask the human for an account that belongs to the company and can create snaps.'],
        'creative_package_not_found' => ['retryable' => false, 'details' => ['package_id'],
            'fix' => 'Use the package id that `export-creative-package` returned. Then call `get-creative-package` again with that id.'],
    ];

    public static function templateNotFound(string $templateId): Response
    {
        return self::make(
            code: 'template_not_found',
            message: "No template with id `{$templateId}` exists in your account.",
            fix: 'Call `list-templates` to read the template ids in your account. '
                .'Then call this tool again with an id from that list.',
            details: ['template_id' => $templateId],
        );
    }

    public static function batchNotFound(string $batchId): Response
    {
        return self::make(
            code: 'batch_not_found',
            message: "No batch with id `{$batchId}` exists in your account.",
            fix: 'Use the batch id that `create-snaps-batch` returned. '
                .'Then call this tool again with that id.',
            details: ['batch_id' => $batchId],
        );
    }

    public static function transparencyNotSupported(): Response
    {
        return self::make(
            code: 'transparency_not_supported',
            message: 'Transparency is not supported for the JPEG format.',
            fix: 'Set `format` to png or webp. To keep jpeg, set `transparent` to false.',
            details: ['format' => 'jpeg', 'transparent' => true],
        );
    }

    public static function idempotencyKeyInProgress(string $message): Response
    {
        return self::make(
            code: 'idempotency_key_in_progress',
            message: $message,
            fix: 'Nothing was created and nothing was charged. Wait a moment. '
                .'Then send the same call again with the same `idempotency_key`.',
            // The one error a retry loop can clear on its own. Another request
            // holds the key right now, so the same input succeeds once it ends.
            retryable: true,
        );
    }

    public static function idempotencyKeyBelongsToSnap(): Response
    {
        return self::make(
            code: 'idempotency_key_belongs_to_snap',
            message: 'This idempotency_key already belongs to a single snap from `create-snap`.',
            fix: 'Send this batch again with a new `idempotency_key`. '
                .'To read the snap that key already made, call `create-snap` with it.',
        );
    }

    public static function idempotencyKeyBelongsToBatch(): Response
    {
        return self::make(
            code: 'idempotency_key_belongs_to_batch',
            message: 'This idempotency_key already belongs to a batch from `create-snaps-batch`.',
            fix: 'Send this call again with a new `idempotency_key`. '
                .'To read the batch that key already made, call `create-snaps-batch` with it.',
        );
    }

    /**
     * The message says the account, not the owner, because `TemplatePolicy`
     * shares a template across the whole company. Every admin and every
     * customer in the owning company passes this gate. The prose this replaced
     * said "Only the template owner can edit this template", which sent the
     * agent to find an owner who does not hold the permission.
     *
     * @param  string  $action  edit or publish. The agent called one tool and
     *                          the message has to name that one.
     */
    public static function templateChangeNotAllowed(string $action): Response
    {
        return self::make(
            code: 'template_change_not_allowed',
            message: "Your account is not allowed to {$action} this template.",
            fix: 'You cannot correct this from a tool. Ask the human for an account role that '
                .'can change templates. You can also call `create-template` to build a template of your own.',
            details: ['action' => $action],
        );
    }

    public static function templatesNotAllowed(): Response
    {
        return self::make(
            code: 'templates_not_allowed',
            message: 'You are not allowed to create templates.',
            fix: 'You cannot correct this from a tool. '
                .'Ask the human for an account role that can create templates.',
        );
    }

    public static function noSizePresetMatch(string $platform): Response
    {
        return self::make(
            code: 'no_size_preset_match',
            message: "No size preset matches `{$platform}`.",
            fix: 'Call `list-template-sizes` with no arguments to read every preset.',
            details: ['platform' => $platform],
        );
    }

    /**
     * @param  array<int, string>  $available
     */
    public static function noPlatformGuideMatch(string $platform, array $available): Response
    {
        return self::make(
            code: 'no_platform_guide_match',
            message: "No platform guide matches `{$platform}`.",
            fix: 'Call `platform-guide` again with a platform from `details.available`. '
                .'To read every platform, call it with no arguments.',
            details: ['platform' => $platform, 'available' => $available],
        );
    }

    /**
     * @param  array<int, string>  $available
     */
    public static function noPurposeGuideMatch(string $purpose, array $available): Response
    {
        return self::make(
            code: 'no_purpose_guide_match',
            message: "No purpose guide matches `{$purpose}`.",
            fix: 'Call `image-purpose-guide` again with a purpose from `details.available`. '
                .'To read every purpose, call it with no arguments.',
            details: ['purpose' => $purpose, 'available' => $available],
        );
    }

    /**
     * One error for every creative package code in `CREATIVE_PACKAGE_ERRORS`.
     *
     * A decoded request that fails answers here as
     * `creative_package_invalid_request`. The rule that decides which failures
     * answer at the transport instead lives on `ValidateCreativePackageEnvelope`.
     */
    public static function creativePackage(CreativePackageClaimException $exception): Response
    {
        return self::make(
            code: $exception->codeName,
            message: $exception->getMessage(),
            fix: self::CREATIVE_PACKAGE_ERRORS[$exception->codeName]['fix'],
            retryable: $exception->retryable,
            details: $exception->details,
        );
    }

    /**
     * The code is the one that `PATCH /api/v1/templates/{uuid}` answers with
     * 409, so the REST and MCP surfaces name the same refusal.
     */
    public static function legacyEditRejectedForV2(): Response
    {
        return self::make(
            code: 'legacy_edit_rejected_for_v2',
            message: 'This template has a v2 editor document. `edit-template` cannot change its layers.',
            fix: 'Call `get-editor-document` to read the document and its revision. '
                .'Then call `apply-editor-operations` or `put-editor-document` with that revision.',
        );
    }

    /** @param array<string, mixed> $details */
    public static function editorOperation(string $code, array $details = []): Response
    {
        [$message, $fix] = match ($code) {
            'editor_document_rate_limited' => ['The editor document rate limit was reached.', 'Wait before calling `get-editor-document` and trying a revised operation.'],
            'editor_operation_document_missing' => ['This template has no v2 editor document.', 'Call `get-template-details` and choose a template with an editor document.'],
            'editor_document_conflict' => ['The editor document changed before this operation was saved.', 'Call `get-editor-document`, preserve the returned document, then apply the operation with its revision.'],
            default => ['The requested editor operation is not allowed by the document contract.', 'Call `describe-editor-document`, correct the operation, then call `apply-editor-operations` again.'],
        };

        return self::make(code: $code, message: $message, fix: $fix, details: $details);
    }

    /**
     * The MCP protocol carries the error as text, and `isError` marks it.
     *
     * The JSON goes in that text on purpose. `structuredContent` is the other
     * channel, but a client only reads it when the tool declares an
     * `outputSchema`, and no tool here declares one. Text reaches every client
     * and every model, and it matches how the successful responses are built.
     *
     * Slashes stay unescaped so that a URL in an error reads as a URL.
     *
     * @param  array<string, mixed>  $details
     */
    private static function make(
        string $code,
        string $message,
        string $fix,
        bool $retryable = false,
        array $details = [],
    ): Response {
        $error = [
            'code' => $code,
            'message' => $message,
            'fix' => $fix,
            'retryable' => $retryable,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return Response::error(json_encode(
            ['error' => $error],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\CreativePackageContract;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * The transport half of the creative package request contract.
 *
 * The rule that splits the two invalid-request shapes: this middleware
 * answers at the transport, with JSON-RPC `-32600` and
 * `Invalid creative package request.`, when the body is oversized (a declared
 * or an actual length over the limit) or when the target envelope cannot be
 * decoded: a JSON-RPC version other than 2.0, an id that is not a string or
 * an integer, or a metadata value that is not a JSON object. Every failure
 * that a decoded request produces, such as a missing field, a bad UUID, more
 * than ten items or a metadata object too deep, answers as the tool error
 * `creative_package_invalid_request` with bounded `fields`.
 * A body that does not parse, or that is not a JSON object, never reaches
 * the package check: it falls through to the native laravel/mcp transport,
 * which answers `-32700`, or `-32600` with its own message.
 * `ToolError::creativePackage` points at this rule, and
 * `CreativePackageRequestShapesTest` pins the shapes.
 */
final class ValidateCreativePackageEnvelope
{
    private const MAX_MCP_REQUEST_BYTES = 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->declaredLengthExceedsOuterLimit($request)) {
            return $this->invalidRequest(null, true);
        }

        $raw = $request->getContent();
        if (strlen($raw) > self::MAX_MCP_REQUEST_BYTES) {
            return $this->invalidRequest(null, true);
        }

        $message = json_decode($raw);

        if (! $this->targetsCreativePackage($message)) {
            return $next($request);
        }

        if (! $this->hasValidEnvelope($message)
            || strlen($raw) > CreativePackageContract::limit('request_bytes', CreativePackageContract::MAX_REQUEST_BYTES)
            || ! $this->metadataAreObjects($message)) {
            return $this->invalidRequest($message->id ?? null);
        }

        return $next($request);
    }

    private function declaredLengthExceedsOuterLimit(Request $request): bool
    {
        $length = $request->header('Content-Length');

        return is_string($length)
            && ctype_digit($length)
            && (int) $length > self::MAX_MCP_REQUEST_BYTES;
    }

    private function targetsCreativePackage(mixed $message): bool
    {
        return $message instanceof stdClass
            && ($message->method ?? null) === 'tools/call'
            && ($message->params ?? null) instanceof stdClass
            && ($message->params->name ?? null) === 'export-creative-package';
    }

    private function hasValidEnvelope(stdClass $message): bool
    {
        if (($message->jsonrpc ?? null) !== '2.0') {
            return false;
        }

        if (! property_exists($message, 'id') || $message->id === null) {
            return true;
        }

        return is_int($message->id) || is_string($message->id);
    }

    private function metadataAreObjects(stdClass $message): bool
    {
        $arguments = $message->params->arguments ?? null;
        if (! $arguments instanceof stdClass || ! isset($arguments->items) || ! is_array($arguments->items)) {
            return true;
        }

        foreach ($arguments->items as $item) {
            if ($item instanceof stdClass && property_exists($item, 'metadata') && ! $item->metadata instanceof stdClass) {
                return false;
            }
        }

        return true;
    }

    private function invalidRequest(mixed $id, bool $includeNullId = false): JsonResponse
    {
        $body = ['jsonrpc' => '2.0'];
        if (is_string($id) || is_int($id)) {
            $body['id'] = $id;
        } elseif ($includeNullId) {
            $body['id'] = null;
        }
        $body['error'] = ['code' => -32600, 'message' => 'Invalid creative package request.'];

        return response()->json($body);
    }
}

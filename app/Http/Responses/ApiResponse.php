<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The envelope rule of the Snapsbrew public REST API, in one place.
 *
 * Every success body that carries a resource is `{"data": ...}`. A list adds
 * `meta`. A write adds `message`. One resource then sits at one depth on every
 * endpoint, so a generated client can model one response type per operation.
 * Before this class, one template read at `response.data.uuid` after a create
 * and at `response.uuid` after a read.
 *
 * A delete is the one exemption. The row is gone, so there is no resource to
 * put under `data`. `ApiResponse::message()` answers the note alone. Do not
 * invent a payload to satisfy the rule.
 *
 * An endpoint backed by an `Illuminate\Http\Resources\Json\JsonResource` calls
 * nothing here. Return the resource itself and Laravel applies the same
 * wrapper from `JsonResource::$wrap`. Never write
 * `response()->json(new SomeResource(...))`: that call reads `jsonSerialize()`
 * and never reaches `toResponse()`, so it answers the fields flat and strips
 * the envelope.
 *
 * Error bodies are out of scope here. Laravel owns `{"message": ...}` and
 * `{"message": ..., "errors": {...}}`, and the editor document endpoints
 * answer `{"code": ...}`.
 */
class ApiResponse
{
    /**
     * One resource, with an optional write note beside it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function data(array $data, ?string $message = null, int $status = 200): JsonResponse
    {
        $body = ['data' => $data];

        if ($message !== null) {
            $body['message'] = $message;
        }

        return response()->json($body, $status);
    }

    /**
     * A page of resources and the cursors that walk it.
     *
     * @param  array<int, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    public static function page(array $data, array $meta, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => $meta], $status);
    }

    /**
     * A write that carries no resource. A delete is the only such write today.
     */
    public static function message(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}

<?php

namespace App\Support;

/**
 * Shared validation rules for `idempotency_key`, accepted by snap creation on
 * both transports (REST and MCP).
 *
 * The character set and the length are a contract. `max:191` is the index-safe
 * utf8mb4 length of `idempotency_keys.key`, so an over-length value fails here
 * as a 422 instead of reaching a strict-mode insert and surfacing as a 500. The
 * regex also rejects an empty string, which must never be stored as a real key.
 */
class IdempotencyRules
{
    /** Matches the column length in the `idempotency_keys` migration. */
    public const MAX_LENGTH = 191;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(string $key = 'idempotency_key'): array
    {
        return [
            $key => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_LENGTH, 'regex:/^[\w-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $key = 'idempotency_key'): array
    {
        return [
            $key.'.regex' => 'idempotency_key accepts letters, digits, underscores and hyphens only.',
        ];
    }
}

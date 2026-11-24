<?php

namespace App\ValueObjects;

class EditorAssetHttpResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly bool $overflow = false,
        public readonly bool $timedOut = false,
    ) {}
}

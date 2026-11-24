<?php

namespace App\Services;

/** The fence one worker holds on one item: the item, its token and its generation. */
final class CreativePackageItemLease
{
    public function __construct(
        public readonly int $itemId,
        public readonly string $token,
        public readonly int $generation,
    ) {}
}

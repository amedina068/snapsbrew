<?php

namespace App\Services;

use App\Models\CreativePackage;

final class CreativePackageClaimResult
{
    public function __construct(
        public readonly CreativePackage $package,
        public readonly bool $replayed,
    ) {}
}

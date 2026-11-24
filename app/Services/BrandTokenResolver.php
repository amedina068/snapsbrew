<?php

namespace App\Services;

use App\Models\BrandToken;

class BrandTokenResolver
{
    public function active(int $companyId, ?string $kind = null)
    {
        return BrandToken::query()->where('company_id', $companyId)->whereNull('retired_at')
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            ->orderBy('kind')->orderBy('name')->orderByDesc('version')->get();
    }
}

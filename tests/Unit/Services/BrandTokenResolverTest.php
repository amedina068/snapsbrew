<?php

namespace Tests\Unit\Services;

use App\Models\BrandToken;
use App\Models\Company;
use App\Services\BrandTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandTokenResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_returns_active_tokens_for_one_company(): void
    {
        $company = Company::factory()->create();
        BrandToken::create(['company_id' => $company->id, 'kind' => 'color', 'name' => 'primary', 'value' => []]);
        BrandToken::create(['company_id' => $company->id, 'kind' => 'color', 'name' => 'old', 'value' => [], 'retired_at' => now()]);
        $this->assertCount(1, (new BrandTokenResolver)->active($company->id));
    }
}

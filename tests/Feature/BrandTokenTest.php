<?php

namespace Tests\Feature;

use App\Models\BrandToken;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_are_versioned_and_scoped_to_company(): void
    {
        $company = Company::factory()->create();
        $token = BrandToken::create(['company_id' => $company->id, 'kind' => 'color', 'name' => 'primary', 'value' => ['hex' => '#123456']]);

        $this->assertNotEmpty($token->uuid);
        $this->assertSame('#123456', $token->value['hex']);
    }
}

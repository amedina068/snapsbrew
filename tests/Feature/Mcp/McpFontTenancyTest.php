<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnapsbrewServer;
use App\Mcp\Tools\PreviewDesignTool;
use App\Models\Company;
use App\Models\Font;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An inline design names a font family on the request, and `SnapBuilder`
 * resolves that family to a company-uploaded file by `company_id`. The design
 * tools take the company from the authenticated user and expose no field for
 * it. This test pins that: a design in one company never reads the font file
 * of another company that uses the same family name.
 */
class McpFontTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function userWithFont(string $fileName): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $font = Font::factory()->create(['company_id' => $company->id, 'family' => 'Brand']);
        $font->addMediaFromString('wOFF'.str_repeat('A', 200))
            ->usingFileName($fileName)
            ->toMediaCollection('file');

        return [$user, $font->fresh()];
    }

    public function test_preview_design_resolves_only_the_callers_company_font()
    {
        Storage::fake('public');

        [$userA, $fontA] = $this->userWithFont('a-brand.woff');
        [, $fontB] = $this->userWithFont('b-brand.woff');

        $this->assertNotSame($fontA->url, $fontB->url);

        $response = SnapsbrewServer::actingAs($userA)->tool(PreviewDesignTool::class, [
            'width' => 100,
            'height' => 100,
            'layers' => [
                ['key' => 'headline', 'type' => 'text', 'properties' => [
                    ['name' => 'textContent', 'value' => 'Hi'],
                    ['name' => 'fontFamily', 'value' => 'Brand'],
                ]],
            ],
        ])->assertOk();

        $response->assertSee($fontA->url);
        $response->assertDontSee($fontB->url);
    }
}

<?php

namespace Tests\Feature;

use App\Builders\SnapBuilder;
use App\Models\Company;
use App\Models\Font;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FontControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->actingAs($user);

        return $user;
    }

    /** A WOFF file is a 'wOFF' magic header followed by arbitrary bytes. */
    private function fakeWoff(string $name = 'brand.woff'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, 'wOFF'.str_repeat('A', 200));
    }

    public function test_the_top_menu_links_to_the_fonts_page()
    {
        $this->actingUser();

        $this->get('/screenshots')
            ->assertOk()
            ->assertSee('href="/fonts"', false);
    }

    public function test_valid_woff_uploads_and_persists_family()
    {
        Storage::fake('public');
        $user = $this->actingUser();

        $response = $this->post('/fonts', [
            'name' => 'Brand Sans',
            'family' => 'Brand Sans',
            'file' => $this->fakeWoff(),
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('fonts', [
            'company_id' => $user->company_id,
            'family' => 'Brand Sans',
        ]);
    }

    public function test_svg_with_woff_extension_is_rejected()
    {
        Storage::fake('public');
        $this->actingUser();

        $file = UploadedFile::fake()->createWithContent('evil.woff', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $response = $this->post('/fonts', [
            'name' => 'Evil',
            'family' => 'Evil',
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('fonts', 0);
    }

    public function test_family_with_unsafe_characters_is_rejected()
    {
        Storage::fake('public');
        $this->actingUser();

        $response = $this->post('/fonts', [
            'name' => 'Bad',
            'family' => "Bad'; }body{",
            'file' => $this->fakeWoff(),
        ]);

        $response->assertSessionHasErrors('family');
    }

    public function test_over_quota_is_rejected()
    {
        Storage::fake('public');
        $user = $this->actingUser();
        Font::factory()->count(Font::MAX_FONTS_PER_COMPANY)->create(['company_id' => $user->company_id]);

        $response = $this->post('/fonts', [
            'name' => 'One Too Many',
            'family' => 'One Too Many',
            'file' => $this->fakeWoff(),
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseCount('fonts', Font::MAX_FONTS_PER_COMPANY);
    }

    public function test_cannot_delete_another_companys_font()
    {
        $this->actingUser();
        $otherFont = Font::factory()->create(['company_id' => Company::factory()->create()->id]);

        $response = $this->delete("/fonts/{$otherFont->uuid}");

        $response->assertForbidden();
        $this->assertDatabaseHas('fonts', ['id' => $otherFont->id]);
    }

    public function test_delete_of_in_use_font_is_blocked_and_names_templates()
    {
        Storage::fake('public');
        $user = $this->actingUser();
        $font = Font::factory()->create(['company_id' => $user->company_id, 'family' => 'BrandSans']);
        Template::factory()->create([
            'company_id' => $user->company_id,
            'title' => 'Hero Banner',
            'layers' => json_encode([
                ['key' => 't', 'properties' => [['name' => 'fontFamily', 'value' => 'BrandSans']]],
            ]),
        ]);

        $response = $this->delete("/fonts/{$font->uuid}");

        $response->assertSessionHasErrors('font');
        $this->assertStringContainsString('Hero Banner', session('errors')->first('font'));
        $this->assertDatabaseHas('fonts', ['id' => $font->id]);
    }

    public function test_force_delete_removes_in_use_font()
    {
        Storage::fake('public');
        $user = $this->actingUser();
        $font = Font::factory()->create(['company_id' => $user->company_id, 'family' => 'BrandSans']);
        Template::factory()->create([
            'company_id' => $user->company_id,
            'layers' => json_encode([
                ['key' => 't', 'properties' => [['name' => 'fontFamily', 'value' => 'BrandSans']]],
            ]),
        ]);

        $response = $this->delete("/fonts/{$font->uuid}", ['force' => 1]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('fonts', ['id' => $font->id]);
    }

    public function test_at_font_face_emitted_only_for_matching_family()
    {
        Storage::fake('public');
        $company = Company::factory()->create();

        $used = Font::factory()->create(['company_id' => $company->id, 'family' => 'UsedFont']);
        $used->addMedia($this->fakeWoff('used.woff'))->toMediaCollection('file');

        $unused = Font::factory()->create(['company_id' => $company->id, 'family' => 'UnusedFont']);
        $unused->addMedia($this->fakeWoff('unused.woff'))->toMediaCollection('file');

        $layers = json_encode([
            ['key' => 't', 'properties' => [['name' => 'fontFamily', 'value' => 'UsedFont']]],
        ]);

        $head = (new SnapBuilder)
            ->setLayers($layers)
            ->forCompany($company->id)
            ->setWidth(100)
            ->setHeight(100)
            ->buildHead();

        $this->assertStringContainsString('@font-face', $head);
        $this->assertStringContainsString('UsedFont', $head);
        $this->assertStringNotContainsString('UnusedFont', $head);
    }

    public function test_no_font_faces_without_company_context()
    {
        Storage::fake('public');
        $company = Company::factory()->create();
        $font = Font::factory()->create(['company_id' => $company->id, 'family' => 'UsedFont']);
        $font->addMedia($this->fakeWoff())->toMediaCollection('file');

        $layers = json_encode([
            ['key' => 't', 'properties' => [['name' => 'fontFamily', 'value' => 'UsedFont']]],
        ]);

        // No forCompany() → no custom @font-face (back-compat with old call sites).
        $head = (new SnapBuilder)->setLayers($layers)->setWidth(100)->setHeight(100)->buildHead();

        $this->assertStringNotContainsString('@font-face', $head);
    }

    public function test_font_upload_creates_no_api_log_or_credit_debit()
    {
        Storage::fake('public');
        $this->actingUser();

        $this->post('/fonts', [
            'name' => 'Free',
            'family' => 'Free',
            'file' => $this->fakeWoff(),
        ]);

        $this->assertDatabaseCount('api_logs', 0);
    }
}

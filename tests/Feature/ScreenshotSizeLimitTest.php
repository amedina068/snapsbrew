<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A screenshot starts a browser with the viewport that the request names. The
 * size has the same bounds as a template canvas.
 */
class ScreenshotSizeLimitTest extends TestCase
{
    use RefreshDatabase;

    public static function badSizes(): array
    {
        return [
            'width over the maximum' => ['width', Template::MAX_DIMENSION + 1, (string) Template::MAX_DIMENSION],
            'height over the maximum' => ['height', 100000, (string) Template::MAX_DIMENSION],
            'width of zero' => ['width', 0, '1'],
            'negative height' => ['height', -5, '1'],
        ];
    }

    #[DataProvider('badSizes')]
    public function test_the_api_refuses_a_size_out_of_bounds(string $field, int $value, string $bound)
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/screenshots', ['url' => 'https://example.invalid', $field => $value]);

        $response->assertStatus(422)->assertJsonValidationErrors($field);
        $this->assertStringContainsString($bound, $response->json("errors.$field.0"));
        $this->assertDatabaseCount('screenshots', 0);
    }

    #[DataProvider('badSizes')]
    public function test_the_web_form_refuses_a_size_out_of_bounds(string $field, int $value, string $bound)
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)
            ->postJson('/screenshots', ['url' => 'https://example.invalid', $field => $value]);

        $response->assertStatus(422)->assertJsonValidationErrors($field);
        $this->assertStringContainsString($bound, $response->json("errors.$field.0"));
        $this->assertDatabaseCount('screenshots', 0);
    }

    public function test_the_api_refuses_a_screenshot_with_no_url()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/screenshots', ['width' => 800]);

        $response->assertStatus(422)->assertJsonValidationErrors('url');
        $this->assertStringContainsString('required', $response->json('errors.url.0'));
        $this->assertDatabaseCount('screenshots', 0);
    }

    public function test_the_web_form_refuses_a_screenshot_with_no_url()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($user)->postJson('/screenshots', ['width' => 800]);

        $response->assertStatus(422)->assertJsonValidationErrors('url');
        $this->assertStringContainsString('required', $response->json('errors.url.0'));
        $this->assertDatabaseCount('screenshots', 0);
    }
}

<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Browsershot\Browsershot;
use Tests\TestCase;

class ScreenshotApiTest extends TestCase
{
    use RefreshDatabase;

    private function createApiUser(array $companyAttributes = []): array
    {
        $company = Company::factory()->create($companyAttributes);
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    /**
     * Make Browsershot return a stub screenshot instead of rendering a page.
     */
    private function fakeBrowsershot(): void
    {
        $browsershot = Mockery::mock('overload:'.Browsershot::class);
        $fluent = [
            'url', 'setRemoteInstance', 'setOption', 'setNodeBinary', 'setNpmBinary',
            'windowSize', 'waitUntilNetworkIdle', 'deviceScaleFactor',
            'fullPage', 'preventUnsuccessfulResponse',
            'setScreenshotType', 'transparentBackground',
        ];
        foreach ($fluent as $method) {
            $browsershot->shouldReceive($method)->andReturnSelf();
        }
        // A valid 20x20 PNG, base64 encoded (decodable by GD for media conversions).
        $browsershot->shouldReceive('base64Screenshot')->andReturn(
            'iVBORw0KGgoAAAANSUhEUgAAABQAAAAUCAIAAAAC64paAAAACXBIWXMAAA7EAAAOxAGVKw4b'
            .'AAAAG0lEQVQ4jWP8z0A+YKJA76jmUc2jmkc1U0EzACKcASfaaIZkAAAAAElFTkSuQmCC'
        );
    }

    public function test_unauthenticated_cannot_list_screenshots()
    {
        $response = $this->getJson('/api/v1/screenshots');

        $response->assertStatus(401);
    }

    public function test_can_list_screenshots()
    {
        [$user, $token] = $this->createApiUser();

        Screenshot::factory()->count(3)->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/screenshots');

        $response->assertStatus(200);
        $response->assertJsonCount(3, 'data');
    }

    /**
     * The table has no `image_url` column. The list once selected one, and
     * SQLite read the unknown quoted name as a string literal, so each item
     * carried a key named `"image_url"` with the quotes. MySQL rejects the
     * same select. The list must return the media URL that the read returns.
     */
    public function test_the_list_returns_the_same_image_url_as_the_read()
    {
        $this->fakeBrowsershot();
        [$user, $token] = $this->createApiUser();

        $created = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/screenshots', ['url' => 'https://example.com'])
            ->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/screenshots')
            ->assertOk();

        $item = $response->json('data.0');

        $this->assertSame(['uuid', 'url', 'image_url', 'width', 'height', 'created_at'], array_keys($item));
        $this->assertSame($created->json('data.id'), $item['uuid']);
        $this->assertSame($created->json('data.image_url'), $item['image_url']);
        $this->assertNotSame('', $item['image_url']);
    }

    /**
     * `ApiSpec` documents `image_url` as `string|null`. The media library
     * answers `""` for a screenshot with no image, and the API must answer
     * `null` instead, on the list and on the read.
     */
    public function test_a_screenshot_with_no_image_returns_a_null_image_url()
    {
        [$user, $token] = $this->createApiUser();

        $screenshot = Screenshot::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);

        $list = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/screenshots')
            ->assertOk();

        $this->assertArrayHasKey('image_url', $list->json('data.0'));
        $this->assertNull($list->json('data.0.image_url'));

        $read = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/screenshots/{$screenshot->uuid}")
            ->assertOk();

        $this->assertArrayHasKey('image_url', $read->json('data'));
        $this->assertNull($read->json('data.image_url'));
    }

    public function test_list_screenshots_only_shows_own_company()
    {
        [$user, $token] = $this->createApiUser();

        Screenshot::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);
        Screenshot::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/screenshots');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_can_show_screenshot_by_uuid()
    {
        [$user, $token] = $this->createApiUser();

        $screenshot = Screenshot::factory()->create([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/screenshots/{$screenshot->uuid}");

        $response->assertStatus(200);
        // `assertJsonFragment` searches every depth, so it passes on the flat
        // body and on the wrapped one. The path names the depth.
        $response->assertJsonPath('data.id', $screenshot->uuid);
    }

    public function test_cannot_show_other_company_screenshot()
    {
        [$user, $token] = $this->createApiUser();
        $otherScreenshot = Screenshot::factory()->create();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/screenshots/{$otherScreenshot->uuid}");

        $response->assertStatus(404);
    }

    public function test_can_create_a_screenshot()
    {
        $this->fakeBrowsershot();
        [$user, $token] = $this->createApiUser([]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/screenshots', [
                'url' => 'https://example.com',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('screenshots', [
            'company_id' => $user->company_id,
            'url' => 'https://example.com',
        ]);

        // The whole body, because the envelope is the contract.
        // `ResourceResponse::calculateStatus()` answers 201 for a POST whose
        // model was recently created, so the status above guards the pin back
        // to 200 that `ScreenshotController::store()` carries.
        $screenshot = Screenshot::where('url', 'https://example.com')->firstOrFail();

        $response->assertExactJson([
            'data' => [
                'id' => $screenshot->uuid,
                'url' => 'https://example.com',
                'image_url' => $screenshot->imageUrl,
                'width' => $screenshot->width,
                'height' => $screenshot->height,
                'created_at' => $screenshot->created_at->toJSON(),
            ],
        ]);
    }

    public function test_creating_a_screenshot_uses_default_dimensions()
    {
        $this->fakeBrowsershot();
        [$user, $token] = $this->createApiUser([]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/screenshots', [
                'url' => 'https://example.com',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('screenshots', [
            'company_id' => $user->company_id,
            'width' => 1330,
            'height' => 780,
        ]);
    }

    public function test_unauthenticated_cannot_create_a_screenshot()
    {
        $response = $this->postJson('/api/v1/screenshots', [
            'url' => 'https://example.com',
        ]);

        $response->assertStatus(401);
    }
}

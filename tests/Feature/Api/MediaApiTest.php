<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileNameNotAllowed;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    public function test_unauthenticated_cannot_list_media()
    {
        $response = $this->getJson('/api/v1/media');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_cannot_upload_media()
    {
        $response = $this->postJson('/api/v1/media');

        $response->assertStatus(401);
    }

    public function test_can_list_own_media()
    {
        Storage::fake('public');
        [$user, $token] = $this->createApiUser();

        $user->addMedia(UploadedFile::fake()->image('one.png'))
            ->toMediaCollection('imagesMedia');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/media');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    public function test_list_media_only_shows_own_uploads()
    {
        Storage::fake('public');
        [$user, $token] = $this->createApiUser();

        $otherUser = User::factory()->create();
        $otherUser->addMedia(UploadedFile::fake()->image('other.png'))
            ->toMediaCollection('imagesMedia');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/media');

        $response->assertStatus(200);
        $response->assertJsonCount(0, 'data');
    }

    public function test_can_upload_an_image()
    {
        Storage::fake('public');
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/media', [
                'file' => UploadedFile::fake()->image('upload.png'),
            ]);

        $response->assertStatus(200);
        // The stored name is random. The client name stays in `name` only.
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.png$/', $response->json('file_name'));
        $response->assertJsonPath('name', 'upload');
        $this->assertCount(1, $user->fresh()->getMedia('imagesMedia'));
    }

    /**
     * `image` reads the content only. A GIF with an HTML tail and an `.html`
     * name passed it, and Spatie stored the file under its client name, so
     * the public disk served a page on the app origin.
     */
    public function test_an_image_polyglot_with_an_html_name_is_rejected()
    {
        Storage::fake('public');
        [, $token] = $this->createApiUser();

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $path = tempnam(sys_get_temp_dir(), 'poly');
        file_put_contents($path, $gif.'<html><p>not an image</p></html>');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/media', ['file' => new UploadedFile($path, 'page.html', null, null, true)], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertSame(0, Media::query()->count());
    }

    public function test_an_image_polyglot_with_an_image_name_is_stored_with_the_detected_extension()
    {
        Storage::fake('public');
        [, $token] = $this->createApiUser();

        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $path = tempnam(sys_get_temp_dir(), 'poly');
        file_put_contents($path, $gif.'<html><p>not an image</p></html>');

        // The client calls it a PNG. The content is a GIF, so the stored name
        // ends in `.gif` and the disk serves it as an image.
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/media', ['file' => new UploadedFile($path, 'photo.png', null, null, true)], ['Accept' => 'application/json']);

        $response->assertStatus(200);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.gif$/', $response->json('file_name'));
    }

    public function test_an_svg_upload_is_rejected()
    {
        Storage::fake('public');
        [, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/api/v1/media', [
                'file' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'),
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
        $this->assertSame(0, Media::query()->count());
    }

    public function test_the_media_library_refuses_a_file_name_that_is_not_an_allowed_type()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        foreach (['page.html', 'logo.svg', 'noextension'] as $name) {
            try {
                $user->addMedia(UploadedFile::fake()->image('a.png'))
                    ->usingFileName($name)
                    ->toMediaCollection('imagesMedia');
                $this->fail("The media library stored {$name}.");
            } catch (FileNameNotAllowed) {
                // Expected.
            }
        }

        $this->assertSame(0, Media::query()->count());
    }

    /**
     * The fields that `ApiSpec` documents for one media item. A new field on
     * the Spatie model must not reach the response without a change here and
     * in the spec.
     */
    private const MEDIA_KEYS = ['created_at', 'file_name', 'id', 'mime_type', 'name', 'original_url', 'size', 'src', 'uuid'];

    private function assertMediaKeys(array $item): void
    {
        $keys = array_keys($item);
        sort($keys);

        $this->assertSame(self::MEDIA_KEYS, $keys);
    }

    public function test_an_upload_returns_only_the_documented_media_fields()
    {
        Storage::fake('public');
        [, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/media', [
                'file' => UploadedFile::fake()->image('upload.png'),
            ])
            ->assertStatus(200);

        $this->assertMediaKeys($response->json());
        $this->assertStringNotContainsString('model_type', $response->getContent());
        $this->assertStringNotContainsString('custom_properties', $response->getContent());
    }

    public function test_the_list_returns_only_the_documented_media_fields_in_a_flat_paginator()
    {
        Storage::fake('public');
        [$user, $token] = $this->createApiUser();

        $media = $user->addMedia(UploadedFile::fake()->image('one.png'))
            ->toMediaCollection('imagesMedia');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/media')
            ->assertStatus(200);

        $this->assertMediaKeys($response->json('data.0'));
        $this->assertSame($media->id, $response->json('data.0.id'));
        $this->assertSame($media->getUrl('image'), $response->json('data.0.src'));
        $this->assertStringNotContainsString('model_type', $response->getContent());

        // The page fields sit next to `data`, as a flat Laravel paginator.
        $keys = array_keys($response->json());
        sort($keys);
        $this->assertSame([
            'current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links',
            'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total',
        ], $keys);
    }

    public function test_uploading_a_non_image_file_is_rejected()
    {
        Storage::fake('public');
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/media', [
                'file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
    }

    public function test_uploading_without_a_file_is_rejected()
    {
        [$user, $token] = $this->createApiUser();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/media', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('file');
    }
}

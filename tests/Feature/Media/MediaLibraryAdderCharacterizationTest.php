<?php

namespace Tests\Feature\Media;

use App\Models\Snap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Safety net for the spatie/laravel-medialibrary 9 -> 10 jump (which pulls in
 * Flysystem 3). Pins the media-write adders used in hot paths and the emitted
 * local-disk URL string so any path/format drift surfaces as a precise red.
 *
 * The expected URL is a HARD-CODED literal captured on Laravel 8 — it must NOT
 * be re-derived from getUrl()/getFirstMediaUrl(), or it would pass trivially
 * after the bump even if the format changed.
 */
class MediaLibraryAdderCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    // 1x1 transparent PNG.
    private const PNG_BASE64 =
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/1P3AAAAAElFTkSuQmCC';

    // L8 baseline for a single User imagesMedia upload (media id 1, file avatar.png).
    private const EXPECTED_USER_MEDIA_URL_L8 = '/storage/1/avatar.png';

    public function test_add_media_from_string_stores_one_item()
    {
        Storage::fake('public');
        $snap = Snap::factory()->create();

        $snap->addMediaFromString('<p>body</p>')->toMediaCollection('html_content');

        $this->assertCount(1, $snap->fresh()->getMedia('html_content'));
        $this->assertNotNull($snap->fresh()->getFirstMedia('html_content'));
    }

    public function test_add_media_from_base64_stores_one_item()
    {
        Storage::fake('public');
        $snap = Snap::factory()->create();

        $snap->addMediaFromBase64(self::PNG_BASE64)
            ->withCustomProperties(['mime-type' => 'image/png'])
            ->usingFileName('snap.png')
            ->toMediaCollection('image');

        $this->assertCount(1, $snap->fresh()->getMedia('image'));
    }

    public function test_add_media_and_first_media_url_is_byte_identical()
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $user->addMedia(UploadedFile::fake()->image('avatar.png'))
            ->toMediaCollection('imagesMedia');

        $this->assertSame(
            self::EXPECTED_USER_MEDIA_URL_L8,
            $user->fresh()->getFirstMediaUrl('imagesMedia')
        );

        // getFirstMediaPath resolves through a different generator method than
        // getUrl; pin its tail so a medialibrary 10 path-format drift (which
        // would break Snap::imagePath / signed-URL responses) surfaces too.
        $this->assertStringEndsWith(
            '/1/avatar.png',
            $user->fresh()->getFirstMediaPath('imagesMedia')
        );
    }
}

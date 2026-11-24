<?php

namespace Tests\Unit\Models;

use App\Models\Snap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Characterizes Snap::readMediaContents() (via the `content`/`head` accessors)
 * across the Flysystem 1 -> 3 boundary. On Laravel 8 a missing file makes
 * Storage::get() return false, cast to ''. On Laravel 9 (Flysystem 3) get()
 * throws League\Flysystem\UnableToReadFile — the missing-file case below must
 * still return '' after the code fix (T-018).
 */
class SnapReadMediaContentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_is_empty_string_when_no_media_collection_exists()
    {
        Storage::fake('public');
        $snap = Snap::factory()->create();

        $this->assertSame('', $snap->content);
        $this->assertSame('', $snap->head);
    }

    public function test_content_round_trips_the_stored_string()
    {
        Storage::fake('public');
        $snap = Snap::factory()->create();

        $snap->addContent('<p>hello world</p>');
        $snap->addHead('<meta charset="utf-8">');

        $this->assertSame('<p>hello world</p>', $snap->fresh()->content);
        $this->assertSame('<meta charset="utf-8">', $snap->fresh()->head);
    }

    public function test_content_is_empty_string_when_backing_file_is_missing()
    {
        Storage::fake('public');
        $snap = Snap::factory()->create();
        $snap->addContent('<p>will be deleted</p>');

        // The media row stays in the DB; remove only its file on disk so
        // getFirstMedia() still resolves but the read fails.
        $media = $snap->getFirstMedia('html_content');
        Storage::disk('public')->deleteDirectory((string) $media->id);

        $this->assertSame('', $snap->fresh()->content);
    }
}

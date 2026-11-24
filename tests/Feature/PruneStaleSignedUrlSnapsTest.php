<?php

namespace Tests\Feature;

use App\Models\Snap;
use App\Models\SnapImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * A signed-url render that failed before the fix left a snap row with its
 * identifier and no image. The command finds those rows and deletes them, and
 * it leaves every other kind of image-less snap alone.
 */
class PruneStaleSignedUrlSnapsTest extends TestCase
{
    use RefreshDatabase;

    private function signedUrlSnap(array $attributes = []): Snap
    {
        $snap = Snap::factory()->completed()->create($attributes + [
            'identifier' => md5((string) random_int(0, PHP_INT_MAX)),
        ]);

        // The factory row is written now. Move it back past the age guard.
        $snap->forceFill(['created_at' => now()->subHour()])->save();

        return $snap;
    }

    /** @return array<string, Snap> */
    private function seedSnaps(): array
    {
        Storage::fake('public');

        $stale = $this->signedUrlSnap();

        $rendered = $this->signedUrlSnap();
        $rendered->addMediaFromBase64(FakeRenderer::FAKE_PNG)
            ->usingFileName('rendered.png')
            ->toMediaCollection('image');

        $import = SnapImport::factory()->create();
        $batchItem = $this->signedUrlSnap([
            'identifier' => 'item_1',
            'snap_import_id' => $import->id,
            'status' => Snap::STATUS_PENDING,
        ]);

        $withoutIdentifier = $this->signedUrlSnap(['identifier' => null]);

        $inFlight = Snap::factory()->create(['identifier' => md5('in-flight')]);

        return compact('stale', 'rendered', 'batchItem', 'withoutIdentifier', 'inFlight');
    }

    public function test_dry_run_is_the_default_and_deletes_nothing()
    {
        $snaps = $this->seedSnaps();

        $this->artisan('snaps:prune-stale-signed-url-snaps')
            ->expectsOutputToContain('Found 1 stale signed-url snap(s)')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(5, Snap::count());
        $this->assertNotNull($snaps['stale']->fresh());
    }

    public function test_force_deletes_only_image_less_signed_url_snaps()
    {
        $snaps = $this->seedSnaps();

        $this->artisan('snaps:prune-stale-signed-url-snaps', ['--force' => true])
            ->expectsOutputToContain('Deleted 1 stale signed-url snap(s)')
            ->assertSuccessful();

        $this->assertNull($snaps['stale']->fresh());

        foreach (['rendered', 'batchItem', 'withoutIdentifier', 'inFlight'] as $kept) {
            $this->assertNotNull($snaps[$kept]->fresh(), "{$kept} must not be deleted");
        }
    }
}

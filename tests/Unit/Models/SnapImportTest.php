<?php

namespace Tests\Unit\Models;

use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SnapImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_snap_import_belongs_to_template()
    {
        $import = SnapImport::factory()->create();

        $this->assertInstanceOf(Template::class, $import->template);
    }

    public function test_snap_import_belongs_to_user()
    {
        $import = SnapImport::factory()->create();

        $this->assertInstanceOf(User::class, $import->user);
    }

    public function test_snap_import_has_many_snaps()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $import = SnapImport::factory()->create([
            'template_id' => $template->id,
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        Snap::factory()->count(5)->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'snap_import_id' => $import->id,
        ]);

        $this->assertCount(5, $import->snaps);
    }

    public function test_progress_snapshot()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $import = SnapImport::factory()->create([
            'template_id' => $template->id,
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'total_rows' => 10,
        ]);

        Snap::factory()->count(3)->completed()->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'snap_import_id' => $import->id,
        ]);
        Snap::factory()->count(2)->failed()->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'snap_import_id' => $import->id,
        ]);
        Snap::factory()->count(1)->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'snap_import_id' => $import->id,
            'status' => Snap::STATUS_PENDING,
        ]);

        $snapshot = $import->progressSnapshot();

        $this->assertEquals($import->uuid, $snapshot['uuid']);
        $this->assertEquals(10, $snapshot['total_rows']);
        $this->assertEquals(3, $snapshot['completed']);
        $this->assertEquals(2, $snapshot['failed']);
        $this->assertEquals(1, $snapshot['pending']);
        $this->assertEquals(5, $snapshot['processed_rows']); // completed + failed
    }

    public function test_status_constants()
    {
        $this->assertEquals('pending', SnapImport::STATUS_PENDING);
        $this->assertEquals('processing', SnapImport::STATUS_PROCESSING);
        $this->assertEquals('completed', SnapImport::STATUS_COMPLETED);
        $this->assertEquals('failed', SnapImport::STATUS_FAILED);
        $this->assertEquals('canceled', SnapImport::STATUS_CANCELED);
    }

    public function test_cancellation_cache_key()
    {
        $key = SnapImport::cancellationCacheKey(42);

        $this->assertEquals('cancel_import_at:42', $key);
    }

    public function test_completed_at_cast()
    {
        $import = SnapImport::factory()->completed()->create();

        $this->assertInstanceOf(Carbon::class, $import->completed_at);
    }

    public function test_canceled_at_cast()
    {
        $import = SnapImport::factory()->canceled()->create();

        $this->assertInstanceOf(Carbon::class, $import->canceled_at);
    }

    public function test_has_uuid()
    {
        $import = SnapImport::factory()->create();

        $this->assertNotNull($import->uuid);
    }
}

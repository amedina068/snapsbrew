<?php

namespace Tests\Unit\Services;

use App\Jobs\ProcessSnap;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use App\Services\SnapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SnapServiceTest extends TestCase
{
    use RefreshDatabase;

    private function template(): Template
    {
        $user = User::factory()->create();

        return Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
    }

    public function test_create_dispatches_process_snap_sync_for_non_async()
    {
        Bus::fake();
        $template = $this->template();

        $snap = app(SnapService::class)->create(
            $template, [], 'image', false, null, $template->user_id, $template->company_id
        );

        $this->assertInstanceOf(Snap::class, $snap);
        $this->assertFalse((bool) $snap->is_async);
        $this->assertSame(Snap::STATUS_PROCESSING, $snap->status);
        Bus::assertDispatchedSync(ProcessSnap::class);
    }

    public function test_create_dispatches_process_snap_async_for_async()
    {
        Bus::fake();
        $template = $this->template();

        $snap = app(SnapService::class)->create(
            $template, [], 'image', true, 'https://example.com/hook', $template->user_id, $template->company_id
        );

        $this->assertTrue((bool) $snap->is_async);
        $this->assertSame(Snap::STATUS_PENDING, $snap->status);
        $this->assertSame('https://example.com/hook', $snap->webhook_url);
        Bus::assertDispatched(ProcessSnap::class);
    }

    public function test_create_persists_snap_with_correct_attributes()
    {
        Bus::fake();
        $template = $this->template();

        $snap = app(SnapService::class)->create(
            $template, [['layer' => 'a', 'text' => 'b']], 'pdf', false, null, $template->user_id, $template->company_id
        );

        $this->assertDatabaseHas('snaps', [
            'id' => $snap->id,
            'template_id' => $template->id,
            'company_id' => $template->company_id,
            'type' => 'pdf',
        ]);
    }
}

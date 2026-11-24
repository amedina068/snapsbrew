<?php

namespace Tests\Unit\Services;

use App\Models\ApiLog;
use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use App\Services\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UsageRecorder writes every usage row of the api_logs table. The rows are a
 * history with no balance, but each unit of work must count one time only.
 */
class UsageRecorderTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['company_id' => Company::factory()->create()->id]);
    }

    private function snap(User $user, array $attributes = []): Snap
    {
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        return Snap::factory()->completed()->create($attributes + [
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);
    }

    private function packageItem(User $user, ?Snap $snap = null): CreativePackageItem
    {
        $package = CreativePackage::factory()->create([
            'company_id' => $user->company_id,
            'created_by' => $user->id,
        ]);

        return CreativePackageItem::factory()->create([
            'company_id' => $user->company_id,
            'creative_package_id' => $package->id,
            'snap_id' => $snap?->id,
        ]);
    }

    public function test_it_settles_the_request_row_with_the_render_details()
    {
        $apiLog = ApiLog::factory()->create([
            'renders' => 0,
            'feature' => 'pending',
            'type' => 'pending',
            'status_code' => 200,
            'response' => null,
        ]);

        UsageRecorder::settle(
            $apiLog,
            renders: 1,
            feature: UsageRecorder::FEATURE_SIGNED_URL,
            type: 'image',
            statusCode: 201,
            response: ['id' => 'abc'],
        );

        $row = $apiLog->fresh();
        $this->assertSame(1, (int) $row->renders);
        $this->assertSame(UsageRecorder::FEATURE_SIGNED_URL, $row->feature);
        $this->assertSame('image', $row->type);
        $this->assertSame(201, (int) $row->status_code);
        $this->assertSame(['id' => 'abc'], json_decode($row->response, true));
    }

    public function test_it_records_one_counted_row_for_a_completed_async_snap()
    {
        $user = $this->user();
        $snap = $this->snap($user, ['is_async' => true]);

        $apiLog = UsageRecorder::recordCompletedAsyncSnap($snap);

        $this->assertNotNull($apiLog);
        $this->assertSame(1, ApiLog::query()->count());
        $this->assertSame(1, ApiLog::query()->post()->successful()->where('renders', 1)->count());
        $this->assertSame($user->id, (int) $apiLog->user_id);
        $this->assertSame(UsageRecorder::FEATURE_API, $apiLog->feature);
        $this->assertSame('snap:'.$snap->uuid, $apiLog->url);
    }

    public function test_it_records_nothing_for_a_sync_snap()
    {
        $snap = $this->snap($this->user(), ['is_async' => false]);

        $this->assertNull(UsageRecorder::recordCompletedAsyncSnap($snap));
        $this->assertSame(0, ApiLog::query()->count());
    }

    public function test_it_records_nothing_for_the_async_snap_of_a_package_item()
    {
        $user = $this->user();
        $snap = $this->snap($user, ['is_async' => true]);
        $this->packageItem($user, $snap);

        $this->assertNull(UsageRecorder::recordCompletedAsyncSnap($snap));
        $this->assertSame(0, ApiLog::query()->count());
    }

    public function test_it_records_one_mcp_row_for_an_mcp_snap()
    {
        $user = $this->user();
        $snap = $this->snap($user);

        $apiLog = UsageRecorder::recordMcpSnap($snap);

        $this->assertSame(1, ApiLog::query()->count());
        $this->assertSame(UsageRecorder::FEATURE_MCP, $apiLog->feature);
        $this->assertSame(1, (int) $apiLog->renders);
        $this->assertSame('mcp:snap:'.$snap->uuid, $apiLog->url);
    }

    public function test_it_records_a_package_item_exactly_one_time()
    {
        $user = $this->user();
        $item = $this->packageItem($user);

        $recorded = UsageRecorder::recordPackageItem($item);

        $this->assertNotNull($recorded->api_log_id);
        $this->assertSame(1, ApiLog::query()->count());

        $apiLog = ApiLog::query()->findOrFail($recorded->api_log_id);
        $this->assertSame(1, (int) $apiLog->renders);
        $this->assertSame(UsageRecorder::FEATURE_MCP, $apiLog->feature);
        $this->assertSame(UsageRecorder::TYPE_CREATIVE_PACKAGE, $apiLog->type);
        $this->assertSame($user->id, (int) $apiLog->user_id);

        // A replay finds the api_log_id and writes nothing.
        $replayed = UsageRecorder::recordPackageItem($item->fresh());

        $this->assertSame($recorded->api_log_id, $replayed->api_log_id);
        $this->assertSame(1, ApiLog::query()->count());
    }
}

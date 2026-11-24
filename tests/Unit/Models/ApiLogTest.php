<?php

namespace Tests\Unit\Models;

use App\Models\ApiLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_log_has_uuid()
    {
        $log = ApiLog::factory()->create();

        $this->assertNotNull($log->uuid);
    }

    public function test_successful_statuses_constant()
    {
        $this->assertEquals([200, 201], ApiLog::SUCCESSFUL_STATUSES);
    }

    public function test_post_method_constant()
    {
        $this->assertEquals('POST', ApiLog::POST_METHOD);
    }

    public function test_scope_post()
    {
        ApiLog::factory()->create([
            'method' => 'POST',
        ]);
        ApiLog::factory()->create([
            'method' => 'GET',
        ]);

        $this->assertCount(1, ApiLog::post()->get());
    }

    public function test_scope_successful()
    {
        ApiLog::factory()->create([
            'status_code' => 200,
        ]);
        ApiLog::factory()->create([
            'status_code' => 201,
        ]);
        ApiLog::factory()->create([
            'status_code' => 500,
        ]);

        $this->assertCount(2, ApiLog::successful()->get());
    }
}

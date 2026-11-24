<?php

namespace Tests\Feature\Mcp;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

class McpSwitchTest extends TestCase
{
    use RefreshDatabase, WithPassportKeys;

    public function test_the_mcp_server_is_on_by_default()
    {
        $this->assertTrue(config('mcp.enabled'));
        $this->postJson('/mcp', [])->assertUnauthorized();
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
    }
}

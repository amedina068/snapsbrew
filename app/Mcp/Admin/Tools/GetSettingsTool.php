<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Mcp\Admin\InstanceSettingsAllowlist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-settings')]
#[Title('Get settings')]
#[Description('Returns the settings that set-setting can change, with the allowed values, and the read-only settings that come from the environment.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetSettingsTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        return self::ok([
            'settings' => InstanceSettingsAllowlist::current(),
            'read_only' => [
                'app_url' => (string) config('app.url'),
                'mail_driver' => (string) config('mail.default'),
                'queue_connection' => (string) config('queue.default'),
                'render_driver' => (string) config('services.render.driver'),
                'mcp_enabled' => (bool) config('mcp.enabled'),
                'mcp_admin_http_enabled' => (bool) config('mcp.admin_enabled'),
                'preview_watermark' => (bool) config('snapsbrew.preview_watermark'),
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

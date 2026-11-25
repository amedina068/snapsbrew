<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Instance\InstanceStatus;
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

#[Name('get-instance-status')]
#[Title('Get instance status')]
#[Description('Returns the version, app URL, database state, pending migrations, queue and scheduler heartbeats, storage link, mail driver, MCP state and whether an owner exists. The same data as php artisan snapsbrew:status --json.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetInstanceStatusTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        return self::ok(app(InstanceStatus::class)->snapshot());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

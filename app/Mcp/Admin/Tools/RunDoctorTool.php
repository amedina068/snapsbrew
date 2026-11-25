<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Instance\InstanceDoctor;
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

#[Name('run-doctor')]
#[Title('Run doctor')]
#[Description('Runs the health checks of php artisan snapsbrew:doctor, with one 16x16 test render. Each check has an id, a status (ok, warn or fail), a message and a fix. This tool runs no fix. To run the safe fixes, run php artisan snapsbrew:doctor --fix on the server.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class RunDoctorTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $checks = app(InstanceDoctor::class)->run(fix: false);

        return self::ok(['checks' => $checks, 'summary' => InstanceDoctor::summary($checks)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

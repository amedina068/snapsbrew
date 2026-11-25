<?php

namespace App\Mcp\Admin\Tools;

use App\Jobs\InstallStarterTemplates;
use App\Mcp\Admin\AdminTool;
use App\Support\StarterTemplates;
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

#[Name('install-starter-templates')]
#[Title('Install the starter templates')]
#[Description('Queues the starter templates for the company of a user. The default user is the first owner. The job skips each starter template that the company already has, so a second call adds nothing.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class InstallStarterTemplatesTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $input = $request->validate(['user_email' => ['nullable', 'string', 'max:255']]);
        $user = $this->userOrOwner($input['user_email'] ?? null);

        if (! $user) {
            return self::userNotFound($input['user_email'] ?? null);
        }

        InstallStarterTemplates::dispatch($user);

        return self::ok([
            'status' => 'queued',
            'user' => ['id' => $user->id, 'email' => $user->email],
            'templates' => array_column(StarterTemplates::all(), 'title'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_email' => $schema->string()->description('The user whose company gets the templates. The default is the first owner.'),
        ];
    }
}

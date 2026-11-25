<?php

namespace App\Mcp\Admin\Tools;

use App\Console\Commands\PruneUnusedOAuthClients;
use App\Mcp\Admin\AdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('prune-oauth-clients')]
#[Title('Prune unused OAuth clients')]
#[Description('Deletes the OAuth clients that registered through /oauth/register and got no access token in 7 days. The scheduler does the same each day. Without confirm: true, the tool returns the count and changes nothing.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class PruneOAuthClientsTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $request->validate(['confirm' => ['nullable', 'boolean']]);

        $count = PruneUnusedOAuthClients::unusedClients()->count();

        if ($count === 0) {
            return self::ok(['status' => 'nothing_to_prune', 'deleted' => 0]);
        }

        if ($preview = $this->needsConfirmation($request, ['action' => 'delete', 'clients' => $count, 'rule' => 'Registered clients with no secret, no owner and no token in '.PruneUnusedOAuthClients::UNUSED_DAYS.' days.'])) {
            return $preview;
        }

        Artisan::call('oauth-clients:prune');

        return self::ok(['status' => 'pruned', 'deleted' => $count]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'confirm' => $schema->boolean()->description('Set to true after the human approved the preview.'),
        ];
    }
}

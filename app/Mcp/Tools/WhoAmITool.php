<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('whoami')]
#[Title('Who am I')]
#[Description('Returns the authenticated account (name, email, company). Call this first to confirm that the token works.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class WhoAmITool extends Tool
{
    public function handle(Request $request): Response
    {
        $user = $request->user();
        $company = $user->company;

        return Response::text(json_encode([
            'name' => $user->name,
            'email' => $user->email,
            'company' => optional($company)->name,
        ], JSON_PRETTY_PRINT));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

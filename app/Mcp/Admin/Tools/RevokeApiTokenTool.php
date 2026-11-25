<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Instance\ApiTokens;
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
use Laravel\Sanctum\PersonalAccessToken;

#[Name('revoke-api-token')]
#[Title('Revoke an API token')]
#[Description('Revokes one API token. Each client that uses the token stops working at once. Without confirm: true, the tool returns a preview and changes nothing.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class RevokeApiTokenTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $input = $request->validate([
            'token_id' => ['required', 'integer'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $token = PersonalAccessToken::query()->with('tokenable')->find($input['token_id']);

        if (! $token) {
            return self::ok(['status' => 'absent', 'token_id' => $input['token_id']]);
        }

        if ($preview = $this->needsConfirmation($request, ['action' => 'revoke', 'token' => ApiTokens::describe($token)])) {
            return $preview;
        }

        ApiTokens::revoke($token->id);

        return self::ok(['status' => 'revoked', 'token_id' => $token->id]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'token_id' => $schema->integer()->description('The id from `list-api-tokens`.')->required(),
            'confirm' => $schema->boolean()->description('Set to true after the human approved the preview.'),
        ];
    }
}

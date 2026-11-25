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

#[Name('list-api-tokens')]
#[Title('List API tokens')]
#[Description('Lists the API tokens of this instance: id, name, user, abilities, last use and expiry. It never returns a token value. Give user_email to show the tokens of one user.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListApiTokensTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $input = $request->validate(['user_email' => ['nullable', 'string', 'max:255']]);
        $query = PersonalAccessToken::query()->with('tokenable')->orderBy('id');

        if ($email = $input['user_email'] ?? null) {
            $user = $this->userOrOwner($email);

            if (! $user) {
                return self::userNotFound($email);
            }

            $query->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id);
        }

        return self::ok(['tokens' => $query->get()->map(fn (PersonalAccessToken $token) => ApiTokens::describe($token))->values()->all()]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_email' => $schema->string()->description('Show only the tokens of the user with this email.'),
        ];
    }
}

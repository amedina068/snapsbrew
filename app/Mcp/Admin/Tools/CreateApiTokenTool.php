<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Support\Instance\ApiTokens;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('create-api-token')]
#[Title('Create an API token')]
#[Description('Creates a product API token (ability api_requests) for a user. The token opens the REST API and the /mcp server of the product, never the admin server. The response holds the token value one time. The default user is the first owner.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CreateApiTokenTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'user_email' => ['nullable', 'string', 'max:255'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $user = $this->userOrOwner($input['user_email'] ?? null);

        if (! $user) {
            return self::userNotFound($input['user_email'] ?? null);
        }

        $expiresAt = isset($input['expires_in_days']) ? CarbonImmutable::now()->addDays($input['expires_in_days']) : null;

        try {
            // Product abilities only. An instance_admin token comes from the CLI.
            $token = ApiTokens::create($user, $input['name'], ApiTokens::PRODUCT_ABILITIES, $expiresAt);
        } catch (InvalidArgumentException $exception) {
            return self::error('invalid_token_request', $exception->getMessage(), 'Correct the arguments and call the tool again.');
        }

        return self::ok(['token' => ApiTokens::describe($token->accessToken) + ['value' => $token->plainTextToken]]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The name of the token.')->required(),
            'user_email' => $schema->string()->description('The user who owns the token. The default is the first owner.'),
            'expires_in_days' => $schema->integer()->description('Days until the token expires. Without it, the token does not expire.'),
        ];
    }
}

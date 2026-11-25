<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use App\Models\Company;
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

#[Name('list-companies')]
#[Title('List companies')]
#[Description('Lists the companies of this instance with the number of users and templates of each.')]
#[IsReadOnly]
#[IsDestructive(false)]
#[IsIdempotent]
#[IsOpenWorld(false)]
class ListCompaniesTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $input = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:500']]);

        $companies = Company::query()
            ->withCount(['users', 'templates'])
            ->orderBy('id')
            ->limit($input['limit'] ?? 100)
            ->get()
            ->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'users_count' => $company->users_count,
                'templates_count' => $company->templates_count,
                'created_at' => $company->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return self::ok(['companies' => $companies]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('The maximum number of companies (1 to 500, default 100).'),
        ];
    }
}

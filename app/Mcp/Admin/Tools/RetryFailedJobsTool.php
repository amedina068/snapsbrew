<?php

namespace App\Mcp\Admin\Tools;

use App\Mcp\Admin\AdminTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('retry-failed-jobs')]
#[Title('Retry failed jobs')]
#[Description('Puts failed jobs back on the queue. Give uuids from get-queue-health, or all: true. A retried job runs again with its side effects, for example a render or a webhook. Without confirm: true, the tool returns a preview and changes nothing.')]
#[IsReadOnly(false)]
#[IsDestructive]
#[IsIdempotent(false)]
#[IsOpenWorld(true)]
class RetryFailedJobsTool extends AdminTool
{
    protected function run(Request $request): Response
    {
        $input = $request->validate([
            'uuids' => ['nullable', 'array', 'max:100'],
            'uuids.*' => ['string', 'max:64'],
            'all' => ['nullable', 'boolean'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $all = (bool) ($input['all'] ?? false);
        $uuids = array_values(array_unique($input['uuids'] ?? []));

        if (! $all && $uuids === []) {
            return self::error('nothing_selected', 'Give `uuids` or `all: true`.', 'Call `get-queue-health` to read the uuids of the failed jobs.');
        }

        $query = DB::table('failed_jobs')->when(! $all, fn ($query) => $query->whereIn('uuid', $uuids));
        $found = $query->pluck('uuid')->all();

        if ($found === []) {
            return self::ok(['status' => 'nothing_to_retry', 'retried' => []]);
        }

        if ($preview = $this->needsConfirmation($request, ['action' => 'retry', 'count' => count($found), 'uuids' => array_slice($found, 0, 100)])) {
            return $preview;
        }

        Artisan::call('queue:retry', ['id' => $all ? ['all'] : $found]);

        return self::ok(['status' => 'retried', 'retried' => $found]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'uuids' => $schema->array()->items($schema->string())->description('The uuids of the failed jobs to retry.'),
            'all' => $schema->boolean()->description('Retry every failed job.'),
            'confirm' => $schema->boolean()->description('Set to true after the human approved the preview.'),
        ];
    }
}

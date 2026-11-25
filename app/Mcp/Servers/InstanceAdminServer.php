<?php

namespace App\Mcp\Servers;

use App\Mcp\Admin\Tools\CreateApiTokenTool;
use App\Mcp\Admin\Tools\GetInstanceStatusTool;
use App\Mcp\Admin\Tools\GetQueueHealthTool;
use App\Mcp\Admin\Tools\GetSettingsTool;
use App\Mcp\Admin\Tools\InstallStarterTemplatesTool;
use App\Mcp\Admin\Tools\InviteUserTool;
use App\Mcp\Admin\Tools\ListApiTokensTool;
use App\Mcp\Admin\Tools\ListCompaniesTool;
use App\Mcp\Admin\Tools\ListUsersTool;
use App\Mcp\Admin\Tools\PruneOAuthClientsTool;
use App\Mcp\Admin\Tools\ReadRecentErrorsTool;
use App\Mcp\Admin\Tools\RetryFailedJobsTool;
use App\Mcp\Admin\Tools\RevokeApiTokenTool;
use App\Mcp\Admin\Tools\RunDoctorTool;
use App\Mcp\Admin\Tools\SendTestEmailTool;
use App\Mcp\Admin\Tools\SetSettingTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * The instance-admin MCP server. It operates the instance, not the product.
 *
 * It runs over stdio by default. An agent starts it on the server:
 *
 *   ssh host docker exec -i <container> php artisan mcp:start snapsbrew-admin
 *
 * With MCP_ADMIN_ENABLED=true, `/mcp/admin` also serves it over HTTP, for an
 * owner with an `instance_admin` token only (`EnsureInstanceAdminAccess`).
 *
 * Each call writes an audit entry (`AdminTool`). These actions stay CLI-only:
 * create the owner, promote or demote a user, rotate keys, revoke all tokens
 * and delete data.
 */
#[Name('Snapsbrew instance admin')]
#[Version('1.0.0')]
#[Instructions(self::INSTRUCTIONS)]
class InstanceAdminServer extends Server
{
    public const INSTRUCTIONS = <<<'MARKDOWN'
This server operates one self-hosted Snapsbrew instance. It does not render images.
For renders, use the product MCP server at `/mcp`.

Start with `get-instance-status`. When a value looks wrong, call `run-doctor`. Each
check has a `fix`. Give the `fix` to the human, or call the tool that it names.

A destructive tool (`revoke-api-token`, `retry-failed-jobs`, `prune-oauth-clients`)
returns a preview first and changes nothing. Show the preview to the human. Call the
tool again with `confirm: true` only after the human agrees.

`create-api-token` creates product tokens only. These actions are not tools. They
stay on the command line of the server: create the owner, promote or demote a user,
rotate keys, revoke all tokens and delete data.
MARKDOWN;

    /** The handle of `php artisan mcp:start`. */
    public const HANDLE = 'snapsbrew-admin';

    /**
     * Every tool is on one page, so a client that does not follow
     * `nextCursor` still sees all of them.
     */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        GetInstanceStatusTool::class,
        RunDoctorTool::class,
        ListUsersTool::class,
        ListCompaniesTool::class,
        InviteUserTool::class,
        ListApiTokensTool::class,
        CreateApiTokenTool::class,
        RevokeApiTokenTool::class,
        GetSettingsTool::class,
        SetSettingTool::class,
        GetQueueHealthTool::class,
        RetryFailedJobsTool::class,
        ReadRecentErrorsTool::class,
        SendTestEmailTool::class,
        InstallStarterTemplatesTool::class,
        PruneOAuthClientsTool::class,
    ];
}

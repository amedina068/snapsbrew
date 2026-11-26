<?php

namespace Tests\Feature\Mcp;

use App\Jobs\InstallStarterTemplates;
use App\Jobs\RecordQueueHeartbeat;
use App\Mail\CompanyInvitationMail;
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
use App\Mcp\Servers\InstanceAdminServer;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Sanctum\PersonalAccessToken;
use ReflectionClass;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class InstanceAdminServerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The wire name and the annotations of each tool. A destructive tool must
     * also return a preview first (tested below).
     */
    private const TOOLS = [
        'get-instance-status' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'run-doctor' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'list-users' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'list-companies' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'invite-user' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'list-api-tokens' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'create-api-token' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'revoke-api-token' => ['readOnlyHint' => false, 'destructiveHint' => true],
        'get-settings' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'set-setting' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'get-queue-health' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'retry-failed-jobs' => ['readOnlyHint' => false, 'destructiveHint' => true],
        'read-recent-errors' => ['readOnlyHint' => true, 'destructiveHint' => false],
        'send-test-email' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'install-starter-templates' => ['readOnlyHint' => false, 'destructiveHint' => false],
        'prune-oauth-clients' => ['readOnlyHint' => false, 'destructiveHint' => true],
    ];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['email' => 'owner@example.com', 'is_owner' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(TestResponse $response): array
    {
        $raw = (fn () => $this->response->toArray())->call($response);

        return json_decode($raw['result']['content'][0]['text'], true);
    }

    private function isError(TestResponse $response): bool
    {
        return (fn () => $this->response->toArray())->call($response)['result']['isError'];
    }

    public function test_the_stdio_server_is_registered_with_the_documented_handle()
    {
        $this->assertNotNull(app(Registrar::class)->getLocalServer('snapsbrew-admin'));
        $this->assertSame('snapsbrew-admin', InstanceAdminServer::HANDLE);
    }

    public function test_the_tool_list_and_the_annotations_are_the_contract()
    {
        $server = new ReflectionClass(InstanceAdminServer::class);
        $tools = $server->getProperty('tools')->getDefaultValue();
        $names = [];

        foreach ($tools as $class) {
            $tool = app($class);
            $names[] = $tool->name();
            $annotations = $tool->annotations();

            foreach (self::TOOLS[$tool->name()] ?? [] as $key => $value) {
                $this->assertSame($value, $annotations[$key] ?? null, "{$tool->name()} {$key}");
            }
        }

        $this->assertSame(array_keys(self::TOOLS), $names);
    }

    public function test_get_instance_status_returns_the_status_snapshot()
    {
        $data = $this->payload(InstanceAdminServer::tool(GetInstanceStatusTool::class));

        $this->assertTrue($data['database']['connected']);
        $this->assertTrue($data['owner_exists']);
        $this->assertArrayHasKey('queue', $data);
    }

    public function test_run_doctor_returns_checks_and_a_summary()
    {
        config(['services.render.driver' => 'browserless', 'services.browserless.url' => '']);

        $data = $this->payload(InstanceAdminServer::tool(RunDoctorTool::class));

        $this->assertSame(['checks', 'summary'], array_keys($data));
        $this->assertSame('fail', collect($data['checks'])->firstWhere('id', 'renderer')['status']);
    }

    public function test_list_users_and_list_companies()
    {
        $company = Company::factory()->create(['name' => 'Max Studio']);
        User::factory()->create(['email' => 'max@example.com', 'company_id' => $company->id]);
        User::factory()->create(['email' => 'maxine@example.com', 'company_id' => $company->id]);

        $users = $this->payload(InstanceAdminServer::tool(ListUsersTool::class, ['search' => 'max']))['users'];
        $this->assertSame(['max@example.com', 'maxine@example.com'], array_column($users, 'email'));
        $this->assertSame(['owner', 'member'], array_column($users, 'company_role'));

        $companies = $this->payload(InstanceAdminServer::tool(ListCompaniesTool::class))['companies'];
        $max = collect($companies)->firstWhere('name', 'Max Studio');
        $this->assertSame(2, $max['users_count']);
        $this->assertSame(0, $max['templates_count']);
    }

    public function test_invite_user_creates_an_invitation_returns_the_link_and_sends_mail()
    {
        Mail::fake();

        $data = $this->payload(InstanceAdminServer::tool(InviteUserTool::class, ['email' => 'New@Example.com']));

        $this->assertSame('created', $data['status']);
        $this->assertSame('new@example.com', $data['email']);
        $this->assertSame($this->owner->company_id, $data['company']['id']);
        $invitation = CompanyInvitation::query()->where('email', 'new@example.com')->firstOrFail();
        $this->assertSame(route('register', ['invitation' => $invitation->token]), $data['invite_url']);
        $this->assertTrue($data['email_sent']);
        Mail::assertSent(CompanyInvitationMail::class);

        // A second call reuses the pending invitation.
        $again = $this->payload(InstanceAdminServer::tool(InviteUserTool::class, ['email' => 'new@example.com', 'send_email' => false]));
        $this->assertSame('exists', $again['status']);
        $this->assertSame(1, CompanyInvitation::count());
    }

    public function test_invite_user_refuses_a_member_and_a_bad_email()
    {
        $response = InstanceAdminServer::tool(InviteUserTool::class, ['email' => 'owner@example.com']);
        $this->assertTrue($this->isError($response));
        $this->assertSame('already_member', $this->payload($response)['error']['code']);

        $response = InstanceAdminServer::tool(InviteUserTool::class, ['email' => 'nope']);
        $this->assertSame('invalid_arguments', $this->payload($response)['error']['code']);
    }

    public function test_create_api_token_creates_only_product_tokens()
    {
        $data = $this->payload(InstanceAdminServer::tool(CreateApiTokenTool::class, ['name' => 'bot', 'expires_in_days' => 30, 'abilities' => ['instance_admin']]));

        $this->assertSame(['api_requests'], $data['token']['abilities']);
        $this->assertSame($this->owner->id, $data['token']['user']['id']);
        $this->assertNotNull($data['token']['expires_at']);
        $this->assertStringContainsString('|', $data['token']['value']);
        $this->assertSame(0, PersonalAccessToken::query()->get()->filter(fn ($token) => in_array('instance_admin', $token->abilities, true))->count());
    }

    public function test_list_api_tokens_never_returns_a_value()
    {
        $this->owner->createToken('one', ['api_requests']);

        $data = $this->payload(InstanceAdminServer::tool(ListApiTokensTool::class, ['user_email' => 'owner@example.com']));

        $this->assertSame(['one'], array_column($data['tokens'], 'name'));
        $this->assertArrayNotHasKey('value', $data['tokens'][0]);
    }

    public function test_revoke_api_token_previews_first_and_revokes_on_confirm()
    {
        $token = $this->owner->createToken('one', ['api_requests'])->accessToken;

        $preview = $this->payload(InstanceAdminServer::tool(RevokeApiTokenTool::class, ['token_id' => $token->id]));
        $this->assertTrue($preview['confirm_required']);
        $this->assertSame('one', $preview['preview']['token']['name']);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id]);

        $done = $this->payload(InstanceAdminServer::tool(RevokeApiTokenTool::class, ['token_id' => $token->id, 'confirm' => true]));
        $this->assertSame('revoked', $done['status']);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->id]);

        $again = $this->payload(InstanceAdminServer::tool(RevokeApiTokenTool::class, ['token_id' => $token->id, 'confirm' => true]));
        $this->assertSame('absent', $again['status']);
    }

    public function test_get_and_set_setting_use_the_allowlist()
    {
        $settings = $this->payload(InstanceAdminServer::tool(GetSettingsTool::class));
        $this->assertSame(Registration::MODES, $settings['settings']['registration_mode']['allowed']);
        $this->assertArrayHasKey('mail_driver', $settings['read_only']);

        $set = $this->payload(InstanceAdminServer::tool(SetSettingTool::class, ['key' => 'registration_mode', 'value' => 'open']));
        $this->assertSame('open', $set['value']);
        $this->assertSame('open', Registration::mode());

        $refused = InstanceAdminServer::tool(SetSettingTool::class, ['key' => 'app_key', 'value' => 'x']);
        $this->assertSame('setting_not_allowed', $this->payload($refused)['error']['code']);

        $refused = InstanceAdminServer::tool(SetSettingTool::class, ['key' => 'registration_mode', 'value' => 'everyone']);
        $this->assertSame('invalid_setting_value', $this->payload($refused)['error']['code']);
    }

    private function failedJob(string $exception = 'RuntimeException: boom'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\RecordQueueHeartbeat', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => ['commandName' => 'App\\Jobs\\RecordQueueHeartbeat', 'command' => serialize(new RecordQueueHeartbeat)]]),
            'exception' => $exception."\n#0 stack",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    public function test_get_queue_health_counts_failed_jobs_and_redacts_the_error()
    {
        config(['queue.default' => 'database', 'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->failedJob('RuntimeException: login failed for token=abc123secret with key '.config('app.key'));

        $data = $this->payload(InstanceAdminServer::tool(GetQueueHealthTool::class));

        $this->assertSame('database', $data['connection']);
        $this->assertSame(1, $data['failed']['count']);
        $this->assertSame('App\\Jobs\\RecordQueueHeartbeat', $data['failed']['newest'][0]['job']);
        $this->assertStringNotContainsString('abc123secret', $data['failed']['newest'][0]['error']);
        $this->assertStringNotContainsString(config('app.key'), $data['failed']['newest'][0]['error']);
        $this->assertSame(0, $data['waiting']['default']);
    }

    public function test_retry_failed_jobs_previews_first_and_retries_on_confirm()
    {
        Queue::fake();
        $uuid = $this->failedJob();

        $none = InstanceAdminServer::tool(RetryFailedJobsTool::class, []);
        $this->assertSame('nothing_selected', $this->payload($none)['error']['code']);

        $preview = $this->payload(InstanceAdminServer::tool(RetryFailedJobsTool::class, ['uuids' => [$uuid]]));
        $this->assertTrue($preview['confirm_required']);
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $done = $this->payload(InstanceAdminServer::tool(RetryFailedJobsTool::class, ['uuids' => [$uuid], 'confirm' => true]));
        $this->assertSame([$uuid], $done['retried']);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_read_recent_errors_returns_the_newest_errors_with_secrets_removed()
    {
        $log = storage_path('logs/admin-tool-test.log');
        config(['database.connections.mysql.password' => 'db-pass-123456']);
        file_put_contents($log, implode("\n", [
            '[2026-11-25 10:00:00] testing.INFO: an info line',
            '[2026-11-25 10:01:00] testing.ERROR: first error with password=hunter2hunter2',
            '#0 /app/Foo.php(10): call()',
            '[2026-11-25 10:02:00] testing.WARNING: a warning',
            '[2026-11-25 10:03:00] testing.ERROR: second error Authorization: Bearer 7|abcdefghijklmnopqrstuvwxyz0123456789 db-pass-123456',
            '',
        ]));
        touch($log, time() + 60);

        try {
            $data = $this->payload(InstanceAdminServer::tool(ReadRecentErrorsTool::class, []));
            $withWarnings = $this->payload(InstanceAdminServer::tool(ReadRecentErrorsTool::class, ['include_warnings' => true]));
        } finally {
            @unlink($log);
        }

        $this->assertSame('admin-tool-test.log', $data['file']);
        $this->assertSame(['ERROR', 'ERROR'], array_column($data['entries'], 'level'));
        $this->assertStringStartsWith('second error', $data['entries'][0]['message']);
        $this->assertStringContainsString('#0 /app/Foo.php', $data['entries'][1]['context']);
        $text = json_encode($data);
        foreach (['hunter2hunter2', 'abcdefghijklmnopqrstuvwxyz0123456789', 'db-pass-123456'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
        $this->assertCount(3, $withWarnings['entries']);
    }

    /**
     * CI sets DB_PASSWORD=password. The value of a secret can thus be the
     * name of a parameter. The parameter value must still go.
     */
    public function test_read_recent_errors_removes_a_parameter_value_when_a_secret_is_the_parameter_name()
    {
        $log = storage_path('logs/admin-tool-test.log');
        config(['database.connections.pgsql.password' => 'password']);
        file_put_contents($log, "[2026-11-25 10:01:00] testing.ERROR: login failed with password=hunter2hunter2\n");
        touch($log, time() + 60);

        try {
            $data = $this->payload(InstanceAdminServer::tool(ReadRecentErrorsTool::class, []));
        } finally {
            @unlink($log);
        }

        $this->assertCount(1, $data['entries']);
        $this->assertStringNotContainsString('hunter2hunter2', json_encode($data));
    }

    public function test_send_test_email_sends_to_the_owner_by_default()
    {
        Mail::fake();

        $data = $this->payload(InstanceAdminServer::tool(SendTestEmailTool::class));

        $this->assertTrue($data['sent']);
        $this->assertSame('owner@example.com', $data['to']);
    }

    public function test_install_starter_templates_queues_the_job_for_the_owner()
    {
        Queue::fake();

        $data = $this->payload(InstanceAdminServer::tool(InstallStarterTemplatesTool::class));

        $this->assertSame('queued', $data['status']);
        Queue::assertPushed(InstallStarterTemplates::class, fn ($job) => $job->user->is($this->owner));

        $missing = InstanceAdminServer::tool(InstallStarterTemplatesTool::class, ['user_email' => 'nobody@example.com']);
        $this->assertSame('user_not_found', $this->payload($missing)['error']['code']);
    }

    public function test_prune_oauth_clients_previews_first_and_prunes_on_confirm()
    {
        $client = Client::query()->forceCreate([
            'id' => (string) Str::uuid(), 'name' => 'Registered', 'secret' => null, 'owner_id' => null, 'owner_type' => null,
            'redirect_uris' => ['https://claude.ai/cb'], 'grant_types' => ['authorization_code'], 'revoked' => false,
        ]);
        $client->forceFill(['created_at' => now()->subDays(8)])->save();

        $preview = $this->payload(InstanceAdminServer::tool(PruneOAuthClientsTool::class));
        $this->assertSame(1, $preview['preview']['clients']);
        $this->assertNotNull(Client::query()->find($client->id));

        $done = $this->payload(InstanceAdminServer::tool(PruneOAuthClientsTool::class, ['confirm' => true]));
        $this->assertSame(1, $done['deleted']);
        $this->assertNull(Client::query()->find($client->id));

        $this->assertSame('nothing_to_prune', $this->payload(InstanceAdminServer::tool(PruneOAuthClientsTool::class, ['confirm' => true]))['status']);
    }

    public function test_each_call_writes_an_audit_entry_without_secret_values()
    {
        InstanceAdminServer::tool(ListUsersTool::class, ['search' => 'owner']);
        InstanceAdminServer::tool(SetSettingTool::class, ['key' => 'registration_mode', 'value' => 'nope']);

        $entries = Activity::query()->where('log_name', 'instance-admin')->orderBy('id')->get();

        $this->assertSame(['list-users', 'set-setting'], $entries->pluck('event')->all());
        $this->assertSame('stdio', $entries[0]->properties['transport']);
        $this->assertSame('ok', $entries[0]->properties['outcome']);
        $this->assertSame('error', $entries[1]->properties['outcome']);
        $this->assertSame('[redacted]', $entries[1]->properties['arguments']['value']);
        $this->assertSame('owner', $entries[0]->properties['arguments']['search']);
    }

    public function test_the_http_route_is_off_by_default()
    {
        $token = $this->owner->createToken('ops', ['instance_admin'], now()->addDay())->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp/admin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertNotFound();
    }
}

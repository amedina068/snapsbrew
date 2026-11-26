<?php

namespace Tests\Feature\Mcp;

use App\Contracts\SnapRenderer;
use App\Mcp\Servers\SnapsbrewServer;
use App\Mcp\ToolError;
use App\Mcp\Tools\CancelSnapsBatchTool;
use App\Mcp\Tools\CreateSnapTool;
use App\Mcp\Tools\CreateTemplateTool;
use App\Mcp\Tools\EditTemplateTool;
use App\Mcp\Tools\GetEditorDocumentTool;
use App\Mcp\Tools\GetSnapsBatchTool;
use App\Mcp\Tools\GetTemplateDetailsTool;
use App\Mcp\Tools\ImagePurposeGuideTool;
use App\Mcp\Tools\ListTemplateSizesTool;
use App\Mcp\Tools\PlatformGuideTool;
use App\Mcp\Tools\PreviewSnapTool;
use App\Mcp\Tools\PreviewTemplateTool;
use App\Mcp\Tools\PublishTemplateTool;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use App\Services\CreativePackageClaimException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Mcp\Response;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\FakeRenderer;
use Tests\Support\ReadsMcpToolErrors;
use Tests\TestCase;

/**
 * An agent reads an error to decide what to call next, so these tests hold the
 * error body to a contract instead of to its wording.
 */
class McpToolErrorTest extends TestCase
{
    use ReadsMcpToolErrors, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
    }

    // ---------------------------------------------------------------- shape

    public function test_every_error_carries_a_code_a_message_and_a_fix()
    {
        foreach ($this->everyError() as $name => $error) {
            $this->assertNotSame('', $error['code'] ?? '', "{$name} has no code");
            $this->assertNotSame('', $error['message'] ?? '', "{$name} has no message");
            $this->assertNotSame('', $error['fix'] ?? '', "{$name} has no fix");
            $this->assertIsBool($error['retryable'] ?? null, "{$name} has no retryable flag");
        }
    }

    public function test_every_code_is_snake_case_and_unique()
    {
        $codes = array_column($this->everyError(), 'code');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*[a-z0-9]$/', $code);
        }

        $this->assertSame(count($codes), count(array_unique($codes)), 'Two errors share one code.');
    }

    /**
     * The fields a `fix` can name that are not tools.
     *
     * The test below reads every backticked token, not only the ones shaped
     * like a tool name. A matcher built around the correct shape cannot see the
     * wrong value: an earlier version required a `-tool` suffix, so a fix that
     * named `list-templates-tool` after the rename would have been skipped
     * rather than failed. This list is the cost of seeing everything, and it is
     * short enough to keep.
     *
     * @var array<int, string>
     */
    private const FIELD_NAMES = [
        'format',
        'transparent',
        'idempotency_key',
        'details.available',
        'details.fields',
        'details.issues',
        'details.package_id',
        'details.poll_after_seconds',
        'template_id',
        'template_version',
    ];

    /**
     * A `fix` that names a tool the server does not register sends the agent
     * nowhere, which is the whole failure this class exists to prevent.
     */
    public function test_every_tool_named_in_a_fix_is_a_tool_the_server_registers()
    {
        $registered = $this->registeredToolNames();
        $this->assertNotEmpty($registered);

        $tools = 0;

        foreach ($this->everyError() as $name => $error) {
            preg_match_all('/`([^`]+)`/', $error['fix'], $matches);

            foreach ($matches[1] as $token) {
                if (in_array($token, self::FIELD_NAMES, true)) {
                    continue;
                }

                $tools++;
                $this->assertContains(
                    $token,
                    $registered,
                    "{$name} points the agent at `{$token}`, which is neither a tool this "
                    .'server registers nor a field in McpToolErrorTest::FIELD_NAMES.'
                );
            }
        }

        $this->assertGreaterThan(0, $tools, 'No fix names a tool. The pattern stopped matching.');
    }

    public function test_only_an_idempotency_key_in_progress_is_retryable()
    {
        $retryable = array_keys(array_filter(
            $this->everyError(),
            fn (array $error) => $error['retryable'] === true
        ));

        $this->assertSame(['idempotencyKeyInProgress'], $retryable);
    }

    /**
     * One factory serves every creative package code. The registry decides the
     * retryable flag and the detail keys, so the test builds one exception per
     * code with every allowed key plus one foreign key, and reads the wire body.
     */
    public function test_every_creative_package_code_answers_with_the_registry_flag_and_its_allowlisted_details()
    {
        $registered = $this->registeredToolNames();
        $retryable = [];

        foreach (ToolError::CREATIVE_PACKAGE_ERRORS as $code => $entry) {
            $details = array_fill_keys($entry['details'], 'value') + ['internal_path' => '/var/www'];
            $exception = new CreativePackageClaimException($code, "Message for {$code}.", $details);

            $error = $this->decode(ToolError::creativePackage($exception))['error'];

            $this->assertSame($code, $error['code']);
            $this->assertSame("Message for {$code}.", $error['message']);
            $this->assertNotSame('', $error['fix'], "{$code} has no fix");
            $this->assertSame($entry['retryable'], $error['retryable'], "{$code} carries the wrong retryable flag");
            $this->assertSame($entry['details'], array_keys($error['details'] ?? []), "{$code} leaks or drops a detail key");

            preg_match_all('/`([^`]+)`/', $error['fix'], $matches);
            foreach ($matches[1] as $token) {
                $this->assertTrue(
                    in_array($token, self::FIELD_NAMES, true) || in_array($token, $registered, true),
                    "{$code} points the agent at `{$token}`, which is neither a tool nor a field."
                );
            }

            if ($error['retryable']) {
                $retryable[] = $code;
            }
        }

        // Only a wait clears a full active slot. Every other package error
        // needs a different input or a person.
        $this->assertSame(['creative_package_rate_limited'], $retryable);
    }

    // ------------------------------------------------------------ call sites

    public function test_a_missing_template_names_the_id_and_the_tool_that_lists_ids()
    {
        $user = $this->makeUser();

        $tools = [
            GetTemplateDetailsTool::class,
            PreviewTemplateTool::class,
            PreviewSnapTool::class,
            CreateSnapTool::class,
            EditTemplateTool::class,
            PublishTemplateTool::class,
        ];

        foreach ($tools as $tool) {
            $response = SnapsbrewServer::actingAs($user)
                ->tool($tool, ['template_id' => 'e8a1c6de-0000-4000-8000-000000000000'])
                ->assertHasErrors();

            $error = $this->errorFrom($response, $tool);

            $this->assertSame('template_not_found', $error['code']);
            $this->assertSame('e8a1c6de-0000-4000-8000-000000000000', $error['details']['template_id']);
            $this->assertStringContainsString('`list-templates`', $error['fix']);
        }
    }

    public function test_a_missing_batch_names_the_id_and_the_tool_that_returns_ids()
    {
        $user = $this->makeUser();

        foreach ([GetSnapsBatchTool::class, CancelSnapsBatchTool::class] as $tool) {
            $response = SnapsbrewServer::actingAs($user)
                ->tool($tool, ['batch_id' => 'b0000000-0000-4000-8000-000000000000'])
                ->assertHasErrors();

            $error = $this->errorFrom($response, $tool);

            $this->assertSame('batch_not_found', $error['code']);
            $this->assertSame('b0000000-0000-4000-8000-000000000000', $error['details']['batch_id']);
            $this->assertStringContainsString('`create-snaps-batch`', $error['fix']);
        }
    }

    public function test_a_jpeg_with_transparency_says_which_field_to_change()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        $response = SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, [
            'template_id' => $template->uuid,
            'format' => 'jpeg',
            'transparent' => true,
        ])->assertHasErrors();

        $error = $this->errorFrom($response, CreateSnapTool::class);

        $this->assertSame('transparency_not_supported', $error['code']);
        $this->assertStringContainsString('`format`', $error['fix']);

        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_a_blocked_edit_names_the_action_and_blames_the_account_not_the_owner()
    {
        $blocked = $this->makeUserWithNoContentRole();
        $template = $this->template($blocked);

        $response = SnapsbrewServer::actingAs($blocked)->tool(EditTemplateTool::class, [
            'template_id' => $template->uuid,
            'title' => 'Renamed',
        ])->assertHasErrors();

        $error = $this->errorFrom($response, EditTemplateTool::class);

        $this->assertSame('template_change_not_allowed', $error['code']);
        $this->assertSame('edit', $error['details']['action']);
        $this->assertStringContainsString('`create-template`', $error['fix']);

        // The template is shared across the company, so naming an owner would
        // send the agent to a person who holds no extra permission.
        $this->assertStringNotContainsStringIgnoringCase('owner', $error['message']);
    }

    /**
     * `PATCH /api/v1/templates/{uuid}` refuses a template that has a v2
     * editor document. The MCP twin must refuse it too. A write to `layers`
     * alone leaves the document stale, and the next document write projects
     * the stale document over the edit.
     */
    public function test_edit_template_refuses_a_template_with_a_v2_editor_document()
    {
        $user = $this->makeUser();
        $template = $this->template($user);
        $template->forceFill([
            'editor_document' => ['schema_version' => 2, 'layers' => [], 'groups' => []],
            'editor_schema_version' => 2,
            'editor_revision' => 1,
        ])->save();
        // Read the stored value. MySQL returns a JSON column with its own format.
        $layersBefore = $template->fresh()->getRawOriginal('layers');

        $response = SnapsbrewServer::actingAs($user)->tool(EditTemplateTool::class, [
            'template_id' => $template->uuid,
            'layers' => [['key' => 'other', 'properties' => [['name' => 'textContent', 'value' => 'New']]]],
        ])->assertHasErrors();

        $error = $this->errorFrom($response, EditTemplateTool::class);

        $this->assertSame('legacy_edit_rejected_for_v2', $error['code']);
        $this->assertStringContainsString('`apply-editor-operations`', $error['fix']);
        $this->assertSame($layersBefore, $template->fresh()->getRawOriginal('layers'));
    }

    public function test_a_company_peer_who_does_not_own_the_template_can_still_edit_it()
    {
        $owner = $this->makeUser();
        $template = $this->template($owner);

        $peer = User::factory()->create([
            'company_id' => $owner->company_id,
            'role' => User::ROLE_CUSTOMER,
        ]);

        SnapsbrewServer::actingAs($peer)->tool(EditTemplateTool::class, [
            'template_id' => $template->uuid,
            'title' => 'Renamed',
        ])->assertOk();
    }

    /**
     * The same peer reads the editor document.
     *
     * `get-editor-document` names the `view` ability and the write tools name
     * `update`. The two abilities read different rules before this change, so
     * the peer wrote the document and could not read the `revision` that the
     * compare-and-swap needs. This test pins the permissive side.
     */
    public function test_a_company_peer_who_does_not_own_the_template_can_still_read_its_editor_document()
    {
        $owner = $this->makeUser();
        $template = $this->template($owner);

        $peer = User::factory()->create([
            'company_id' => $owner->company_id,
            'role' => User::ROLE_CUSTOMER,
        ]);

        SnapsbrewServer::actingAs($peer)->tool(GetEditorDocumentTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"revision": 0');
    }

    /**
     * The publish tool describes a gate, so it must describe the gate the code
     * tests. `TemplatePolicy::update` tests two conditions: the caller acts as
     * a customer, and the caller belongs to the company that owns the template.
     * Neither one is ownership. The prose this replaced said "owner only", so
     * an agent that could not publish was sent to a person who holds no extra
     * permission.
     */
    public function test_the_publish_tool_prose_names_no_owner()
    {
        $tool = app(PublishTemplateTool::class);

        $this->assertStringNotContainsStringIgnoringCase('owner', $tool->title());
        $this->assertStringNotContainsStringIgnoringCase('owner', $tool->description());
    }

    /**
     * Pin the permissive case, not only the denial. This test fails on the day
     * `TemplatePolicy::update` becomes owner-based, which is the day the old
     * wording would have become true.
     */
    public function test_a_company_peer_who_does_not_own_the_template_can_still_publish_it()
    {
        Bus::fake(); // skip the queued version-preview render
        $owner = $this->makeUser();
        $template = $this->template($owner);

        $peer = User::factory()->create([
            'company_id' => $owner->company_id,
            'role' => User::ROLE_CUSTOMER,
        ]);

        SnapsbrewServer::actingAs($peer)->tool(PublishTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"published_version": 1');

        $this->assertDatabaseHas('template_versions', [
            'template_id' => $template->id,
            'version_number' => 1,
        ]);
    }

    public function test_an_unknown_guide_key_lists_the_keys_that_do_exist()
    {
        $user = $this->makeUser();

        $cases = [
            [PlatformGuideTool::class, ['platform' => 'myspace'], 'no_platform_guide_match', 'platform'],
            [ImagePurposeGuideTool::class, ['purpose' => 'holograph'], 'no_purpose_guide_match', 'purpose'],
            [ListTemplateSizesTool::class, ['platform' => 'myspace'], 'no_size_preset_match', 'platform'],
        ];

        foreach ($cases as [$tool, $arguments, $code, $key]) {
            $response = SnapsbrewServer::actingAs($user)->tool($tool, $arguments)->assertHasErrors();

            $error = $this->errorFrom($response, $tool);

            $this->assertSame($code, $error['code']);
            $this->assertSame(reset($arguments), $error['details'][$key]);
        }
    }

    public function test_a_guide_error_carries_the_keys_the_agent_can_choose_from()
    {
        $user = $this->makeUser();

        $response = SnapsbrewServer::actingAs($user)
            ->tool(PlatformGuideTool::class, ['platform' => 'myspace'])
            ->assertHasErrors();

        $error = $this->errorFrom($response, PlatformGuideTool::class);

        $this->assertNotEmpty($error['details']['available']);
        $this->assertContains('instagram', $error['details']['available']);
    }

    public function test_a_user_who_cannot_create_templates_is_not_sent_to_another_tool()
    {
        $user = $this->makeUserWithNoContentRole();

        $response = SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Blocked',
            'size' => 'instagram_post',
        ])->assertHasErrors();

        $error = $this->errorFrom($response, CreateTemplateTool::class);

        $this->assertSame('templates_not_allowed', $error['code']);
        $this->assertStringContainsString('human', $error['fix']);
    }

    // ------------------------------------------------------------- helpers

    /**
     * Build one of every error, so that a new method cannot skip the contract.
     *
     * @return array<string, array<string, mixed>>
     */
    private function everyError(): array
    {
        $arguments = [
            'templateNotFound' => ['t-1'],
            'batchNotFound' => ['b-1'],
            'transparencyNotSupported' => [],
            'idempotencyKeyInProgress' => ['A request with this idempotency_key is already in progress.'],
            'idempotencyKeyBelongsToSnap' => [],
            'idempotencyKeyBelongsToBatch' => [],
            'templateChangeNotAllowed' => ['edit'],
            'templatesNotAllowed' => [],
            'noSizePresetMatch' => ['myspace'],
            'noPlatformGuideMatch' => ['myspace', ['instagram']],
            'noPurposeGuideMatch' => ['holograph', ['link_preview']],
            'editorOperation' => ['editor_operation_invalid_value'],
            'legacyEditRejectedForV2' => [],
            'creativePackage' => [new CreativePackageClaimException('creative_package_invalid_request', 'The creative package request is invalid.', ['fields' => ['items']])],
        ];

        $builders = collect((new ReflectionClass(ToolError::class))->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn (ReflectionMethod $method) => $method->getName())
            ->all();

        $this->assertEqualsCanonicalizing(
            array_keys($arguments),
            $builders,
            'ToolError gained or lost a method. Add it to this list so the contract covers it.'
        );

        $errors = [];

        foreach ($arguments as $method => $args) {
            $errors[$method] = $this->decode(ToolError::$method(...$args))['error'];
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode((string) $response->content(), true);

        $this->assertIsArray($decoded, 'An error body was not JSON.');

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function registeredToolNames(): array
    {
        $property = (new ReflectionClass(SnapsbrewServer::class))->getProperty('tools');
        $property->setAccessible(true);

        return array_map(fn (string $tool) => app($tool)->name(), $property->getDefaultValue());
    }

    private function makeUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return $user;
    }

    private function template(User $user): Template
    {
        return Template::factory()->withLayers([
            ['key' => 'headline', 'type' => 'text', 'properties' => [
                ['name' => 'textContent', 'value' => 'Hi'],
            ]],
        ])->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => '100',
            'height' => '100',
        ]);
    }

    /**
     * `TemplatePolicy` gates on `actsAsCustomer()`, which admin and customer
     * both pass. Only a third role reaches the denial branch.
     */
    private function makeUserWithNoContentRole(): User
    {
        return User::factory()->create([
            'company_id' => Company::factory()->create()->id,
            'role' => 'viewer',
        ]);
    }
}

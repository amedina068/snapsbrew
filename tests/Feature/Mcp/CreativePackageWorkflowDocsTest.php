<?php

namespace Tests\Feature\Mcp;

use App\Contracts\SnapRenderer;
use App\Mcp\Servers\SnapsbrewServer;
use App\Models\ApiLog;
use App\Models\User;
use App\Support\CreativePackageContract;
use App\Support\CreativePackageSerializer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsCreativePackages;
use Tests\Support\CallsCreativePackageMcpTools;
use Tests\Support\FakeRenderer;
use Tests\Support\InteractsWithMcp;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

/**
 * The creative package section of the agent workflow, checked against the
 * code it describes.
 *
 * The section holds a state table, a retry rule, two tool names and two numbers. Each of
 * those is a claim about a constant or a response, and prose beside a
 * constant ages into fiction. Every test here reads the claim out of the
 * prose by its shape, then compares it with the code, so that a wrong value
 * fails and a missing value fails too.
 */
class CreativePackageWorkflowDocsTest extends TestCase
{
    use BuildsCreativePackages;
    use CallsCreativePackageMcpTools;
    use InteractsWithMcp;
    use RefreshDatabase;
    use WithPassportKeys;

    private const SECTION_START = 'Creative packages: ';

    private const SECTION_END = "\nErrors: ";

    private const TOOLS = ['export-creative-package', 'get-creative-package'];

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWithPassportKeys();
        CarbonImmutable::setTestNow($this->now());
        Storage::fake('public');
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
        config(['queue.default' => 'database']);
    }

    public function test_the_workflow_section_names_both_tools_and_skill_md_carries_the_same_bytes(): void
    {
        $section = self::section(SnapsbrewServer::WORKFLOW);

        foreach (self::TOOLS as $tool) {
            $this->assertStringContainsString("`{$tool}`", $section);
        }

        // The route renders the same constant. The whole section is compared,
        // not a sample of it, so that a hand-typed copy fails on its first edit.
        $this->assertSame($section, self::section($this->get('/skill.md')->assertOk()->getContent()));
    }

    public function test_the_state_table_is_the_next_action_map_the_serializer_emits(): void
    {
        $this->assertSame(CreativePackageContract::NEXT_ACTIONS, self::stateTable());
    }

    public function test_the_numbers_the_section_states_are_the_contract_limits(): void
    {
        $section = self::section(SnapsbrewServer::WORKFLOW);

        $this->assertSame(1, preg_match('/renders up to (\d+) FINAL images/', $section, $items));
        $this->assertSame(1, preg_match('/for (\d+) days\./', $section, $days));

        $this->assertSame(CreativePackageContract::MAX_ITEMS, (int) $items[1]);
        $this->assertSame(CreativePackageContract::REPLAY_DAYS, (int) $days[1]);
    }

    /**
     * Export, poll, work, poll again: the real route with the `database`
     * queue, so that `building` is a state the client reads. Every observed
     * (status, next_action) pair must be a row of the table, and the run
     * must reach every row, so that no row describes a state that never ships.
     */
    public function test_a_transcript_over_the_real_route_follows_the_table_row_by_row(): void
    {
        $actor = $this->member();
        $token = $this->token($actor);
        $table = self::stateTable();
        $transcript = [];

        $export = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $this->request('doc-ready', [$this->source20($actor), $this->source20($actor)])));
        $transcript[] = $this->pair($export);
        $transcript[] = $this->pair($this->read($token, $export['package_id']));
        // One job, then a read: the first item settles while the package still builds.
        $this->work(maxJobs: 1);
        $half = $this->read($token, $export['package_id']);
        $transcript[] = $this->pair($half);
        $this->assertSame([1, 1, 1], [ApiLog::count(), $half['counts']['ready'], $half['counts']['pending']]);
        $this->work();
        $whole = $this->read($token, $export['package_id']);
        $transcript[] = $this->pair($whole);
        $this->assertSame([2, 2], [ApiLog::count(), $whole['counts']['ready']]);

        // The 1080x1080 source fails its output check against the 20x20 fake renderer.
        $mixed = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $this->request('doc-partial', [$this->source20($actor), $this->source($actor, 1)])));
        $this->work();
        $transcript[] = $this->pair($this->read($token, $mixed['package_id']));

        $doomed = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $this->request('doc-failed', [$this->source($actor, 2)])));
        $this->work();
        $transcript[] = $this->pair($this->read($token, $doomed['package_id']));

        $this->assertSame([
            ['building', 'poll'],
            ['building', 'poll'],
            ['building', 'poll'],
            ['ready', 'download'],
            ['partial', 'retry_incomplete'],
            ['failed', 'inspect_failures'],
        ], $transcript);
        foreach ($transcript as [$status, $nextAction]) {
            $this->assertSame($table[$status], $nextAction, "The table promises another next_action for `{$status}`.");
        }
        $this->assertSame(array_keys($table), array_values(array_unique(array_column($transcript, 0))));
    }

    /**
     * The retry rule says to clear the cause before the replay. This is the
     * measured reason: the creator loses the right to create snaps after the
     * first item renders. A same-key replay by another member reads the
     * package, reopens nothing and queues nothing. An agent that replays in a
     * loop without clearing the cause sees the same response every time.
     */
    public function test_a_same_key_replay_before_the_cause_clears_reopens_no_item_and_queues_no_job(): void
    {
        $creator = $this->member();
        $request = $this->request('doc-blocked', [$this->source20($creator), $this->source20($creator)]);
        $started = $this->mcpToolPayload($this->tool($this->token($creator), 'export-creative-package', $request));
        $this->work(maxJobs: 1);
        User::query()->whereKey($creator->id)->update(['role' => 'viewer']);
        $this->work();
        $token = $this->token($this->memberOf($creator->company));
        $before = $this->read($token, $started['package_id']);
        $this->assertSame(['partial', 'creative_package_entitlement_required', true], [$before['status'], $before['items'][1]['failure']['code'], $before['items'][1]['retryable']]);
        $this->assertSame(0, DB::table('jobs')->count());

        $replay = $this->mcpToolPayload($this->tool($token, 'export-creative-package', $request));

        $this->assertSame([$started['package_id'], true, 'partial', 'retry_incomplete'], [$replay['package_id'], $replay['replayed'], $replay['status'], $replay['next_action']]);
        $this->assertSame($before['items'][1], $replay['items'][1]);
        $this->assertSame(['failed', 1], [$replay['items'][1]['status'], $replay['items'][1]['attempts']]);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * A retryable failure whose fix tells the agent to do something before
     * the replay has a cause to clear. The rule must name every such code,
     * read from the catalog by the shape of its fix: a sentence, then
     * "Then export ... with the same idempotency_key".
     */
    public function test_the_retry_rule_names_every_retryable_code_whose_fix_names_a_cause(): void
    {
        $this->assertSame(1, preg_match('/^Retry rule: (.+?)\n\n/ms', self::section(SnapsbrewServer::WORKFLOW), $rule));

        $withCause = array_keys(array_filter(
            CreativePackageSerializer::FAILURES,
            fn (array $failure) => preg_match('/\. Then export .*same idempotency_key/', $failure[1]) === 1
        ));

        $this->assertNotEmpty($withCause, 'The catalog holds no retryable failure with a cause to clear.');
        foreach ($withCause as $code) {
            $this->assertStringContainsString("`{$code}`", $rule[1], "The retry rule does not name `{$code}`.");
        }
    }

    public function test_the_tool_count_the_server_docblock_states_is_the_count_the_route_serves(): void
    {
        $source = file_get_contents((new \ReflectionClass(SnapsbrewServer::class))->getFileName());

        $this->assertSame(1, preg_match('/registers (\d+) tools/', $source, $stated));
        $this->assertStringNotContainsString(' tools', self::section(SnapsbrewServer::WORKFLOW), 'The workflow states a tool count that this test does not read.');

        $tools = $this->toolsOverHttp();

        $this->assertCount((int) $stated[1], $tools);
        $this->assertSame(self::TOOLS, array_values(array_intersect(array_column($tools, 'name'), self::TOOLS)));
    }

    public function test_the_editor_document_description_carries_no_package_guidance(): void
    {
        $descriptions = array_column($this->toolsOverHttp(), 'description', 'name');

        $this->assertArrayHasKey('describe-editor-document', $descriptions);
        $this->assertStringNotContainsStringIgnoringCase('creative package', $descriptions['describe-editor-document']);
        $this->assertStringNotContainsString('export-creative-package', $descriptions['describe-editor-document']);
    }

    // --------------------------------------------------------------- helpers

    /** The section between its opening words and the errors section. */
    private static function section(string $text): string
    {
        $start = strpos($text, self::SECTION_START);
        $end = strpos($text, self::SECTION_END, (int) $start);
        if ($start === false || $end === false) {
            self::fail('The workflow holds no creative packages section.');
        }

        return substr($text, $start, $end - $start);
    }

    /**
     * The table, read by its shape: three cells split by a bar, indented four
     * spaces. The header row is dropped after a check, so that a renamed
     * column fails here instead of reading as one more state.
     *
     * @return array<string, string> status => next_action
     */
    private static function stateTable(): array
    {
        preg_match_all('/^ {4}(\S+) +\| +(\S+) +\| +(\S.*)$/m', self::section(SnapsbrewServer::WORKFLOW), $rows, PREG_SET_ORDER);

        self::assertNotEmpty($rows, 'The section holds no state table.');
        self::assertSame(['status', 'next_action', 'what you do'], array_slice(array_shift($rows), 1));

        return array_combine(array_column($rows, 1), array_column($rows, 2));
    }

    /** @return array<int, array{name: string, description: string}> */
    private function toolsOverHttp(): array
    {
        $token = $this->token($this->member());
        $response = $this->forgetTheCaller()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
        $response->assertOk();
        $this->assertNull($response->json('result.nextCursor'), 'tools/list needs a second page.');

        return $response->json('result.tools');
    }

    /** @param array<string, mixed> $payload */
    private function pair(array $payload): array
    {
        return [$payload['status'], $payload['next_action']];
    }

    /** @return array<string, mixed> */
    private function read(string $token, string $packageId): array
    {
        return $this->mcpToolPayload($this->tool($token, 'get-creative-package', ['package_id' => $packageId]));
    }
}

<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Mcp\Servers\SnapsbrewServer;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tool name is the only part of this server an agent has to get exactly
 * right, and it is the one part nothing used to check.
 *
 * Without a `#[Name]` attribute, `Primitive::name()` falls back to
 * `Str::kebab(class_basename($this))`, which turns `CreateSnapTool` into
 * `create-snap-tool`. Every document this product ships — the server
 * instructions, the `skill.md` route, the public guide — says `create-snap`.
 * An agent that followed the instructions called a tool that did not exist,
 * and no test could see it, because each side was self-consistent.
 *
 * These tests read the names over HTTP, which is the only place the two sides
 * meet.
 */
class McpToolNamesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The name of every tool, in the order the server registers them.
     *
     * This list is the contract. Changing a line here renames a tool for every
     * caller that already wired it, so change one only on purpose.
     *
     * @var array<int, string>
     */
    private const NAMES = [
        'whoami',
        'list-templates',
        'get-template-details',
        'get-editor-document',
        'describe-editor-document',
        'list-variant-properties',
        'preview-template',
        'preview-snap',
        'preview-design',
        'create-snap',
        'create-snap-from-design',
        'create-snaps-batch',
        'get-snaps-batch',
        'cancel-snaps-batch',
        'describe-layers',
        'design-guide',
        'platform-guide',
        'image-purpose-guide',
        'list-template-sizes',
        'create-template',
        'edit-template',
        'publish-template',
        'put-editor-document',
        'apply-editor-operations',
        'preflight-editor-document',
        'export-creative-package',
        'get-creative-package',
    ];

    public function test_the_wire_names_are_the_names_every_document_promises()
    {
        $this->assertSame(self::NAMES, $this->toolNamesOverHttp());
    }

    public function test_no_tool_name_carries_the_suffix_the_class_name_adds()
    {
        foreach ($this->toolNamesOverHttp() as $name) {
            $this->assertStringEndsNotWith(
                '-tool',
                $name,
                "Tool [{$name}] lost its #[Name] attribute and fell back to the class name."
            );
        }
    }

    /**
     * The workflow is the text an agent reads before its first call, and it
     * names tools in prose. A name no tool answers to sends the agent to a call
     * that cannot succeed.
     *
     * The prose is hard-wrapped, so a line break can split a name in two:
     * "`list-\ntemplate-names`". The match reads one line at a time, so the one
     * name the check cannot see is the one in that position. Normalize first:
     * collapse every run of whitespace into one space, then close the gap
     * around a hyphen, which is where a kebab-case name breaks.
     */
    public function test_every_tool_the_workflow_names_is_a_tool_that_exists()
    {
        $prose = preg_replace('/\s+/', ' ', SnapsbrewServer::WORKFLOW);
        // The gap closes around every hyphen, not only a hyphen a line break
        // split. A backticked phrase with a spaced hyphen, for example
        // `well - formed`, therefore reads as the tool name `well-formed` and
        // the check fails on it. The workflow holds no such phrase today. If
        // you add one, write the phrase without backticks.
        $prose = preg_replace('/\s*-\s*/', '-', $prose);

        preg_match_all('/`([a-z][a-z0-9-]+)`/', $prose, $matches);

        // The workflow also backticks response fields and variant keys. A
        // kebab-case token, or `whoami`, reads as a tool name.
        $named = array_filter(
            array_unique($matches[1]),
            fn (string $token) => $token === 'whoami' || str_contains($token, '-')
        );

        $unknown = array_values(array_diff($named, self::NAMES, [
            'image_url', 'background_color', 'qr_content', 'start_here', 'link_preview',
        ]));

        $this->assertSame([], $unknown, 'The workflow names tools that do not exist.');
    }

    /**
     * Read the names the way a connector reads them: one `tools/list` call over
     * the real route. Reflection on the tool classes would pass even if the
     * server never served these names.
     *
     * @return array<int, string>
     */
    private function toolNamesOverHttp(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
            ]);

        $response->assertOk();

        // One page, on purpose. `SnapsbrewServer::$defaultPaginationLength`
        // carries the reason.
        $this->assertNull(
            $response->json('result.nextCursor'),
            'tools/list needs a second page, so a client that ignores the cursor loses tools.'
        );

        return array_column($response->json('result.tools'), 'name');
    }
}

<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnapsbrewServer;
use App\Mcp\Tools\CreateSnapTool;
use ReflectionClass;
use Tests\TestCase;

/**
 * The MCP directories read these annotations to decide how to present a tool, and
 * a client reads them to decide whether to ask the human before it calls one.
 * Every value here is a deliberate ruling. Read
 * `.council/mcp-tool-annotations/RUN.md` before you change one.
 */
class McpToolAnnotationsTest extends TestCase
{
    /**
     * The expected wire annotations, keyed by tool name.
     *
     * openWorldHint is true only where the tool accepts a caller-supplied URL,
     * through `variants.*.image_url` or `layers[].properties[].backgroundImage`.
     * Read-only and open-world is a real combination: both preview tools fetch a
     * URL the caller controls.
     */
    private const EXPECTED = [
        // The order matches `SnapsbrewServer::$tools`. Each key is the wire
        // name, which a `#[Name]` attribute on the tool sets.
        // `McpToolNamesTest` holds that list over HTTP.
        'whoami' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'list-templates' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'get-template-details' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'get-editor-document' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'describe-editor-document' => ['readOnlyHint' => true, 'idempotentHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'list-variant-properties' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'preview-template' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => true],
        'preview-snap' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => true],
        // A design on the request carries `layers[].properties[].backgroundImage`,
        // a caller-supplied URL, so the world is open. It writes nothing, so it is read-only.
        'preview-design' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => true],
        'create-snap' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true],
        // Same ruling as create-snap: it renders a final image and records
        // usage, and the layers carry caller-supplied URLs.
        'create-snap-from-design' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true],
        // Same ruling as create-snap, and it matters more here: one call can
        // render 100 final images. openWorldHint is true because each item carries
        // `variants.*.image_url`, and `webhook_url` is a second outbound address.
        'create-snaps-batch' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true],
        // A pure read of a batch the caller already started. It reaches no
        // address outside this product, so the world is closed.
        'get-snaps-batch' => ['readOnlyHint' => true, 'idempotentHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        // Destructive and idempotent together is deliberate. Cancel discards
        // queued work that cannot be resumed, and a second cancel leaves the
        // same state. It reaches no address outside this product.
        'cancel-snaps-batch' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
        'describe-layers' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'design-guide' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        // The two guidance tools read a static catalog and take a single string
        // filter. Same ruling as design-guide: read-only, idempotent, closed world.
        'platform-guide' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'image-purpose-guide' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'list-template-sizes' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        'create-template' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => true],
        'edit-template' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true],
        'publish-template' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
        'put-editor-document' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
        'apply-editor-operations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false],
        'preflight-editor-document' => ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
        // Same ruling as create-snaps-batch: one call can render 10 final images.
        // Idempotent because `idempotency_key` is required, so every retry
        // returns the same package. Closed world because the request carries
        // no caller-supplied URL and every source is an owned template version.
        'export-creative-package' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
        // A pure read of a package the caller already started. It signs URLs
        // and reaches no address outside this product.
        'get-creative-package' => ['readOnlyHint' => true, 'idempotentHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
    ];

    /**
     * Reads the list from the server itself, so a new tool cannot skip this test.
     *
     * @return array<int, class-string>
     */
    private function serverTools(): array
    {
        return (new ReflectionClass(SnapsbrewServer::class))->getDefaultProperties()['tools'];
    }

    public function test_the_server_registers_twenty_seven_tools()
    {
        $this->assertCount(27, $this->serverTools());
    }

    public function test_every_registered_tool_has_an_expected_annotation_set()
    {
        $names = [];

        foreach ($this->serverTools() as $class) {
            $tool = app($class);
            $name = $tool->name();
            $names[] = $name;

            $this->assertArrayHasKey(
                $name,
                self::EXPECTED,
                "Tool [{$name}] has no expected annotation set. Add one to McpToolAnnotationsTest."
            );

            $annotations = $tool->toArray()['annotations'];

            // Compare only the three hints this slice rules on. idempotentHint is
            // out of scope and belongs to the idempotency_key work.
            //
            // The base array is $annotations, not the expected table. Keep that
            // order. A key that the tool never declares is then absent from
            // $actual, and assertSame fails on the length. Reverse the arguments
            // and a missing key passes in silence.
            $actual = array_intersect_key($annotations, self::EXPECTED[$name]);
            $expected = self::EXPECTED[$name];

            // The attribute order on the class decides the emitted key order, and
            // that order carries no meaning. Compare by key.
            ksort($actual);
            ksort($expected);

            $this->assertSame(
                $expected,
                $actual,
                "Tool [{$name}] does not carry the expected annotations."
            );
        }

        $this->assertSame(
            array_keys(self::EXPECTED),
            $names,
            'The server tool list and the expected annotation table disagree.'
        );
    }

    public function test_every_registered_tool_declares_a_title_and_a_description()
    {
        foreach ($this->serverTools() as $class) {
            $tool = app($class)->toArray();

            $this->assertNotEmpty($tool['title'], "Tool [{$tool['name']}] has no title.");
            $this->assertNotEmpty($tool['description'], "Tool [{$tool['name']}] has no description.");
        }
    }

    public function test_create_snap_is_not_marked_idempotent()
    {
        // A retry renders a second snap today. UsageRecorder::recordMcpSnap writes
        // a fresh row per call. This annotation becomes true only when create-snap
        // accepts an idempotency_key and a retry returns the same snap.
        $annotations = app(CreateSnapTool::class)->toArray()['annotations'];

        $this->assertFalse(
            $annotations['idempotentHint'] ?? false,
            'create-snap must not claim idempotency until a retry returns the same snap.'
        );
    }
}

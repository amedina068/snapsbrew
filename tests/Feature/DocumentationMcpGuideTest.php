<?php

namespace Tests\Feature;

use App\Mcp\Servers\SnapsbrewServer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The MCP guide is written by hand, so a new tool reaches it only when a
 * person adds it. These tests hold the guide and the server workflow to the
 * two design tools.
 */
class DocumentationMcpGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_guide_lists_both_design_tools_and_the_inline_branch_of_the_walkthrough()
    {
        $text = strip_tags((string) $this->get('/documentation/guides/mcp')->assertOk()->getContent());

        $this->assertStringContainsString('preview-design', $text);
        $this->assertStringContainsString('create-snap-from-design', $text);

        // The walkthrough ("How a render goes") comes before the tool table,
        // and the inline branch has to appear inside it, not only in the table.
        $walkthrough = substr($text, strpos($text, 'How a render goes'), strpos($text, 'Tools', strpos($text, 'How a render goes')) - strpos($text, 'How a render goes'));

        $this->assertStringContainsString('preview-design', $walkthrough, 'The walkthrough does not mention the inline branch.');
    }

    public function test_the_workflow_prose_names_both_design_tools_and_the_reuse_signal()
    {
        $prose = preg_replace('/\s+/', ' ', SnapsbrewServer::WORKFLOW);

        $this->assertStringContainsString('`preview-design`', $prose);
        $this->assertStringContainsString('`create-snap-from-design`', $prose);
        $this->assertStringContainsString('`create-template` first', $prose);
    }
}

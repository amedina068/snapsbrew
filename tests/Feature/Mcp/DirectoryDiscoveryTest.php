<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnapsbrewServer;
use App\Support\ApiDocs\ApiSpec;
use Tests\TestCase;

/**
 * `llms.txt` and `skill.md` are what a directory reviewer and an agent read
 * before they ever call the API. Both render at request time. Neither is a file
 * on disk, because a stored copy of the API surface goes stale.
 */
class DirectoryDiscoveryTest extends TestCase
{
    public function test_llms_txt_is_public_and_plain_text()
    {
        $response = $this->get('/llms.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_llms_txt_lists_every_api_group_from_the_spec()
    {
        $body = $this->get('/llms.txt')->getContent();

        foreach (ApiSpec::groups() as $group) {
            $this->assertStringContainsString(
                $group['title'],
                $body,
                "llms.txt does not name the [{$group['slug']}] group."
            );
            $this->assertStringContainsString(
                '/documentation/'.$group['slug'].'.md',
                $body,
                "llms.txt does not link the [{$group['slug']}] group."
            );
        }
    }

    public function test_llms_txt_points_at_the_skill_and_the_full_reference()
    {
        $body = $this->get('/llms.txt')->getContent();

        $this->assertStringContainsString('/skill.md', $body);
        $this->assertStringContainsString('/documentation/reference.md', $body);
        $this->assertStringContainsString('/documentation/openapi.yaml', $body);
    }

    public function test_skill_md_is_public_and_markdown()
    {
        $response = $this->get('/skill.md');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=UTF-8');
    }

    public function test_skill_md_carries_the_frontmatter_a_skill_file_needs()
    {
        $body = $this->get('/skill.md')->getContent();

        $this->assertStringStartsWith("---\nname: snapsbrew\ndescription: ", $body);
        $this->assertStringContainsString("\n---\n", $body);
    }

    public function test_skill_md_renders_the_one_workflow_definition()
    {
        // The guard that matters. The MCP server sends this exact string as its
        // instructions. If somebody pastes a hand-written workflow into the
        // route, the two copies drift and this test fails.
        $body = $this->get('/skill.md')->getContent();

        $this->assertStringContainsString(SnapsbrewServer::WORKFLOW, $body);
    }

    public function test_the_mcp_instructions_and_the_skill_read_the_same_constant()
    {
        $attributes = (new \ReflectionClass(SnapsbrewServer::class))->getAttributes();

        $instructions = null;

        foreach ($attributes as $attribute) {
            if (str_contains($attribute->getName(), 'Instructions')) {
                $instructions = $attribute->newInstance()->value;
            }
        }

        $this->assertNotNull($instructions, 'SnapsbrewServer declares no #[Instructions].');
        $this->assertSame(SnapsbrewServer::WORKFLOW, $instructions);
    }

    public function test_llms_txt_names_the_configured_legal_pages_outside_a_skippable_section()
    {
        config([
            'snapsbrew.privacy_url' => 'https://example.com/privacy',
            'snapsbrew.terms_url' => 'https://example.com/terms',
        ]);

        $body = $this->get('/llms.txt')->getContent();

        // `## Optional` is a real llms.txt section and it means "skip this if you
        // are short on time". A directory submission checks for a privacy policy,
        // so that link must not sit under a heading that says to skip it.
        $this->assertStringNotContainsString('## Optional', $body);
        $this->assertStringContainsString('Privacy: https://example.com/privacy', $body);
        $this->assertStringContainsString('Terms: https://example.com/terms', $body);
    }

    public function test_llms_txt_omits_the_legal_pages_when_none_are_configured()
    {
        config(['snapsbrew.privacy_url' => null, 'snapsbrew.terms_url' => null]);

        $body = $this->get('/llms.txt')->getContent();

        $this->assertStringNotContainsString('Privacy: ', $body);
        $this->assertStringNotContainsString('Terms: ', $body);
    }

    public function test_skill_md_tells_a_reader_how_to_connect_before_step_one()
    {
        $body = $this->get('/skill.md')->getContent();

        // The workflow opens at `whoami`. A reader who has not connected yet
        // cannot act on step 1 without the endpoint and the setup page.
        $this->assertStringContainsString('Connect: ', $body);
        $this->assertStringContainsString('/mcp', $body);
        $this->assertStringContainsString('/documentation/guides/mcp', $body);

        $connect = strpos($body, 'Connect: ');
        $firstStep = strpos($body, 'whoami');

        $this->assertNotFalse($firstStep);
        $this->assertLessThan($firstStep, $connect, 'The connect line must come first.');
    }
}

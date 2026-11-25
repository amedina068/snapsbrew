<?php

namespace App\Support\ApiDocs;

use App\Mcp\Servers\SnapsbrewServer;

/**
 * Renders `skill.md`, an Agent Skill file that carries the MCP workflow.
 *
 * The body is {@see SnapsbrewServer::WORKFLOW}, the same string the MCP server
 * sends as its instructions. This class adds the frontmatter a skill file needs
 * and the line that tells a reader how to connect. It never restates the
 * workflow. Two copies of a workflow drift by the second edit.
 */
class SkillFileGenerator
{
    private const DESCRIPTION = 'Render branded images from Snapsbrew templates. Use when '
        .'the user asks to create a social image, a banner, an ad creative or a QR code '
        .'from a saved template. Preview first, then create.';

    public function toMarkdown(string $site): string
    {
        $site = rtrim($site, '/');

        $out = [];
        $out[] = '---';
        $out[] = 'name: snapsbrew';
        $out[] = 'description: '.self::DESCRIPTION;
        $out[] = '---';
        $out[] = '';
        $out[] = '# Snapsbrew';
        $out[] = '';

        // The workflow below opens at `whoami`. A reader who has not connected
        // yet needs the endpoint and the setup page before step 1 means anything.
        $out[] = 'Connect: '.$site.'/mcp (Streamable HTTP, Bearer token).';
        $out[] = 'Setup: '.$site.'/documentation/guides/mcp';
        $out[] = '';
        $out[] = SnapsbrewServer::WORKFLOW;
        $out[] = '';
        array_push($out, ...AgentOperations::lines());

        return implode("\n", $out);
    }
}

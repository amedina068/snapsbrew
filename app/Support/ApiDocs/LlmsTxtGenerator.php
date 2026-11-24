<?php

namespace App\Support\ApiDocs;

/**
 * Renders `llms.txt`, the convention that points a model at a site's docs.
 *
 * Built from {@see ApiSpec} at request time, like every other export in this
 * namespace. A file stored in `public/` becomes a second copy of the API
 * surface and goes stale the first time a group is renamed.
 */
class LlmsTxtGenerator
{
    public function toText(string $site): string
    {
        $info = ApiSpec::info();
        $site = rtrim($site, '/');

        $out = [];
        $out[] = '# '.$info['title'];
        $out[] = '';
        $out[] = '> '.$info['description'];
        $out[] = '';
        $out[] = 'You call it from any stack over REST, or from an AI agent over MCP.';
        $out[] = 'A preview is watermarked. A final render is not.';
        $out[] = '';
        $out[] = '## Docs';
        $out[] = '';

        foreach (ApiSpec::groups() as $group) {
            $out[] = sprintf(
                '- [%s](%s/documentation/%s.md): %s',
                $group['title'],
                $site,
                $group['slug'],
                $group['tagline']
            );
        }

        $out[] = '';
        $out[] = '## For agents';
        $out[] = '';
        $out[] = '- ['.$info['title'].' full reference]('.$site.'/documentation/reference.md): every endpoint in one Markdown file.';
        $out[] = '- [OpenAPI schema]('.$site.'/documentation/openapi.yaml): the same spec as OpenAPI.';
        $out[] = '- [Agent skill]('.$site.'/skill.md): the recommended tool order for the MCP server.';
        $out[] = '- [MCP guide]('.$site.'/documentation/guides/mcp): how to connect an agent.';
        $out[] = '';

        // Privacy and terms stay outside a section. A directory submission checks
        // for a privacy policy, and `## Optional` tells a reader to skip it. A
        // self-hosted instance links its own pages, or none.
        if ($privacy = config('snapsbrew.privacy_url')) {
            $out[] = 'Privacy: '.$privacy;
        }
        if ($terms = config('snapsbrew.terms_url')) {
            $out[] = 'Terms: '.$terms;
        }
        $out[] = '';
        $out[] = 'Contact: '.$info['contactEmail'];
        $out[] = '';

        return implode("\n", $out);
    }
}

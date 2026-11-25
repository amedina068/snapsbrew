<?php

namespace Tests\Feature;

use App\Support\ApiDocs\AgentOperations;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * `llms.txt` and `skill.md` name the agent commands. Each name must be a
 * registered Artisan command, or an agent runs a command that does not exist.
 */
class AgentOperationsDocsTest extends TestCase
{
    public function test_every_command_the_sections_name_is_registered()
    {
        $text = implode("\n", AgentOperations::lines());
        preg_match_all('/\bsnapsbrew:[a-z-]+(?::[a-z-]+)*/', $text, $matches);
        $named = array_unique($matches[0]);

        $this->assertNotEmpty($named);
        $this->assertContains('snapsbrew:install', $named);
        $this->assertContains('snapsbrew:doctor', $named);

        foreach ($named as $command) {
            $this->assertArrayHasKey($command, Artisan::all(), "The docs name {$command}, which is not a command.");
        }
    }

    public function test_llms_txt_and_skill_md_carry_both_sections()
    {
        foreach (['/llms.txt', '/skill.md'] as $path) {
            $body = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('## Install with an agent', $body, $path);
            $this->assertStringContainsString('## Operate this instance', $body, $path);
            $this->assertStringContainsString('php artisan mcp:start snapsbrew-admin', $body, $path);
            $this->assertStringContainsString('Run the `connect.env` line first', $body, $path);
            $this->assertStringContainsString('--header "Authorization: Bearer ${SNAPSBREW_TOKEN}"', $body, $path);
        }
    }
}

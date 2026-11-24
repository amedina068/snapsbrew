<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Mcp\Servers\SnapsbrewServer;
use App\Models\Company;
use App\Models\User;
use App\Support\ApiDocs\OpenApiGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use ReflectionClass;
use Tests\TestCase;

/**
 * A word-list guard over the template surfaces.
 *
 * `TemplatePolicy::update` reads `actsAsCustomer()` and `company_id`. It never
 * reads `user_id`, so the account that created a template holds no permission
 * its company peers lack. Prose that gives that account a permission sends a
 * reader to find a person who cannot help. This repository has shipped that
 * defect three times, and the last count was six copies across three files.
 *
 * READ THIS BEFORE YOU TRUST IT. A regular expression matches a word list. It
 * does not match a category. This test finds a claim that uses a word from the
 * WORD LIST below, on a surface from the SURFACES list below. A claim written
 * with any other word passes. A claim written on any other surface passes. Do
 * not read a green run as "no surface claims ownership". Read it as "no
 * surface uses these words".
 *
 * WORD LIST: owner, own behind a subject, owned, belongs to a person, creator,
 * created pointed at a person or a row, author, authored, made behind a
 * subject, originator. The last four arrived after an audit wrote four
 * ordinary claims with them and every one passed.
 *
 * SURFACES: the public MCP guide page, the rendered `documentation/templates`
 * screen, every OpenAPI operation tagged `Templates`, the server instructions,
 * and every tool the `/mcp` route serves. The two REST surfaces arrived after
 * the same audit wrote one claim into `ApiSpec`, which reached four public
 * documents with no guard on any of them.
 *
 * When you find a word or a surface this test cannot see, add it here. That is
 * the whole maintenance rule.
 */
class TemplateOwnershipClaimTest extends TestCase
{
    use RefreshDatabase;

    /** The OpenAPI tag that carries every template operation. */
    private const TEMPLATE_TAG = 'Templates';

    /**
     * The word list, each entry named by the words it holds.
     *
     * The six claims this branch removed could trip only the first two entries.
     * The rest guard sentences nobody has written on these surfaces yet.
     *
     * The bare word `own` is not here on purpose. "its own controls" and "your
     * own agent" are ordinary English on these same pages, so the verb needs a
     * subject in front of it before it reads as a claim.
     *
     * @var array<string, string>
     */
    private const OWNERSHIP_CLAIMS = [
        'the noun "owner"' => '/\bowners?\b/i',
        'the verb "own" with a subject' => '/\b(?:i|you|we|they|he|she|it|who|whoever|that|which|account|accounts|user|users|person|people|caller|callers|company)\s+owns?\b/i',
        'the word "owned"' => '/\bowned\b/i',
        '"belongs to" a person' => '/\bbelongs?\s+to\s+(?:you|your|me|my|him|her|them|their)\b/i',
        'the noun "creator"' => '/\bcreators?\b/i',
        '"created" pointed at a person or a row' => '/\b(?:who|that)\s+created\b|\bcreated\s+(?:it|this|them|by|the\s+\w+)\b/i',
        'the word "author"' => '/\bauthors?\b/i',
        'the word "authored"' => '/\bauthored\b/i',
        'the verb "made" with a subject' => '/\b(?:i|you|we|they|he|she|it|who|whoever|that|which|account|accounts|user|users|person|people|caller|callers|company)\s+made\b/i',
        'the noun "originator"' => '/\boriginators?\b/i',
    ];

    public function test_no_template_surface_uses_a_word_from_the_ownership_list(): void
    {
        $surfaces = $this->surfaces();
        $hits = [];

        foreach ($surfaces as $label => $text) {
            foreach (self::OWNERSHIP_CLAIMS as $words => $pattern) {
                if (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE) === 1) {
                    $hits[] = $label.' carries '.$words.': "'.$this->quote($text, $match[0][1]).'"';
                }
            }
        }

        $this->assertSame([], $hits, 'A template surface uses a word from the ownership list.');
    }

    /**
     * The five surfaces of the list above, read the way a reader reads them.
     *
     * A helper that returns an empty set passes the test above in silence, so
     * every source here proves it served something before it is read.
     *
     * @return array<string, string>
     */
    private function surfaces(): array
    {
        // The two web pages are read first. A bearer request to `/mcp` resolves
        // the default guard to Sanctum's RequestGuard, and `AuthenticateSession`
        // then fails on the next web request in the same test.
        $guide = $this->get('/documentation/guides/mcp');
        $guide->assertOk();
        $guideText = strip_tags((string) $guide->getContent());
        $this->assertStringContainsString('edit-template', $guideText, 'The guide page served no tool table.');

        $screen = $this->get('/documentation/templates');
        $screen->assertOk();
        $screenText = strip_tags((string) $screen->getContent());
        $this->assertStringContainsString('publish', $screenText, 'The templates screen served no endpoint list.');

        $surfaces = [
            'the public MCP guide' => $guideText,
            'the templates documentation screen' => $screenText,
        ];

        $surfaces += $this->openApiTemplateOperations();

        $token = $this->mcpToken();

        $instructions = (string) $this->rpc($token, 2, 'initialize', [
            'protocolVersion' => '2025-03-26',
            'capabilities' => [],
            'clientInfo' => ['name' => 'test', 'version' => '1'],
        ])->json('result.instructions');
        $this->assertNotSame('', $instructions, 'The server served no instructions.');
        $surfaces['the server instructions'] = $instructions;

        $list = $this->rpc($token, 3, 'tools/list');
        $this->assertNull(
            $list->json('result.nextCursor'),
            'tools/list needs a second page, so this test reads only part of the set.'
        );

        $tools = $list->json('result.tools');
        $this->assertCount(
            $this->registeredToolCount(),
            $tools,
            'The wire served a different number of tools than the server registers.'
        );

        foreach ($tools as $tool) {
            // The whole entry, because a claim can sit in a title, in a
            // description or in the description of one argument.
            $surfaces['the tool `'.$tool['name'].'`'] = (string) json_encode($tool);
        }

        return $surfaces;
    }

    /**
     * Every OpenAPI operation the `Templates` tag carries.
     *
     * `ApiSpec` is the one source of the OpenAPI document, the Postman
     * collection, the Markdown export and the documentation screen, so a claim
     * written there reaches four public documents at once. The document is
     * generated here, because that is the artifact a reader downloads.
     *
     * The tag selects the set, not the URL. The embedded-forms group names "the
     * form owner" in a true sentence about the usage log. That group is a
     * different operation family.
     *
     * @return array<string, string>
     */
    private function openApiTemplateOperations(): array
    {
        $document = (new OpenApiGenerator)->toArray();

        $this->assertContains(
            self::TEMPLATE_TAG,
            array_column($document['tags'], 'name'),
            'The OpenAPI document carries no '.self::TEMPLATE_TAG.' tag, so this test selects nothing.'
        );

        $operations = [];

        foreach ($document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                if (in_array(self::TEMPLATE_TAG, $operation['tags'] ?? [], true)) {
                    $label = 'the OpenAPI operation `'.strtoupper($method).' '.$path.'`';
                    $operations[$label] = (string) json_encode($operation);
                }
            }
        }

        $this->assertArrayHasKey(
            'the OpenAPI operation `POST /v1/templates/{templateUuid}/publish`',
            $operations,
            'The publish operation left the '.self::TEMPLATE_TAG.' tag, so this test reads the wrong set.'
        );

        return $operations;
    }

    private function mcpToken(): string
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;
    }

    private function rpc(string $token, int $id, string $method, array $params = []): TestResponse
    {
        $body = ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method];

        if ($params !== []) {
            $body['params'] = $params;
        }

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/mcp', $body);
        $response->assertOk();

        return $response;
    }

    private function registeredToolCount(): int
    {
        $property = (new ReflectionClass(SnapsbrewServer::class))->getProperty('tools');
        $property->setAccessible(true);

        return count($property->getDefaultValue());
    }

    /** The matched words with enough text around them to read the sentence. */
    private function quote(string $text, int $offset): string
    {
        $start = max(0, $offset - 60);

        return trim((string) preg_replace('/\s+/', ' ', substr($text, $start, 160)));
    }
}

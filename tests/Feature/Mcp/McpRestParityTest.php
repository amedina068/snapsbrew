<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\Company;
use App\Models\User;
use App\Support\ApiDocs\ApiSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithMcp;
use Tests\TestCase;

/**
 * The MCP server and the REST API are two faces of one product. An agent calls
 * a tool and a customer calls an endpoint, and both expect the same capability.
 *
 * Nothing held the two faces against each other before this test. A tool can
 * land with no documented endpoint behind it, and the build stays green,
 * because each side is tested against itself.
 *
 * This test reads the tool names over the real `/mcp` route and follows
 * `nextCursor` until it is null. Reflection over the tool classes passes even
 * when the server serves something else, and one page drops every tool past the
 * page size. A first `tools/list` once returned 15 of 18 tools here, and the
 * three it dropped were the three that author templates.
 *
 * The test signs in with a bearer token and never calls `actingAs()`. Sanctum
 * reads the `web` guard before the bearer token and gives a session user a
 * `TransientToken`, so a session left open makes every later token assertion
 * meaningless.
 */
class McpRestParityTest extends TestCase
{
    use InteractsWithMcp;
    use RefreshDatabase;

    /**
     * Every registered tool, mapped to the `ApiSpec` endpoints that carry the
     * same capability over REST.
     *
     * The map is written by hand and read by hand. A tool is not a route, so
     * two tools cover two endpoints each: one tool call with an argument
     * reads one record, and the same call without the argument reads the index.
     *
     * A tool with no REST twin carries an empty list and a reason on its line,
     * and its name must also appear in `TOOLS_WITH_NO_REST_TWIN` below.
     *
     * @var array<string, array<int, string>>
     */
    private const TOOL_TO_ENDPOINTS = [
        // `whoami` answers the identity question and the company question.
        // `GET /v1/auth` answers both. The field names are held against each
        // other in `test_whoami_returns_no_field_the_rest_api_cannot_answer`
        // below, because an endpoint that exists can still answer less than
        // the tool.
        'whoami' => ['auth.show'],
        'list-templates' => ['templates.index'],
        'get-template-details' => ['templates.show'],
        'get-editor-document' => ['editor-documents.show'],
        'describe-editor-document' => ['guides.editor-document'],
        'list-variant-properties' => ['guides.variant-properties'],
        'preview-template' => ['preview.template'],
        'preview-snap' => ['preview.snap'],
        // An inline design is an MCP-only capability for now: the REST API
        // renders from a saved template. A REST twin is its own change.
        'preview-design' => [],
        'create-snap' => ['snaps.store'],
        'create-snap-from-design' => [],
        'create-snaps-batch' => ['snap-batches.store'],
        'get-snaps-batch' => ['snap-batches.show'],
        'cancel-snaps-batch' => ['snap-batches.cancel'],
        'describe-layers' => ['guides.layers'],
        'design-guide' => ['guides.design'],
        // Called with a `platform`, this tool reads one guide. Called with
        // none, it lists every platform.
        'platform-guide' => ['guides.platform', 'guides.platforms'],
        // Called with a `purpose`, this tool reads one guide. Called with none,
        // it lists every purpose.
        'image-purpose-guide' => ['guides.purpose', 'guides.purposes'],
        'list-template-sizes' => ['templates.sizes'],
        'create-template' => ['templates.store'],
        'edit-template' => ['templates.update'],
        'publish-template' => ['templates.publish'],
        'put-editor-document' => ['editor-documents.update'],
        'apply-editor-operations' => ['editor-documents.apply'],
        'preflight-editor-document' => ['editor-documents.preflight'],
        // The creative package ships on MCP first. A REST twin is a later
        // change, so both names sit in TOOLS_WITH_NO_REST_TWIN below.
        'export-creative-package' => [],
        'get-creative-package' => [],
    ];

    /**
     * Tools that carry a capability the REST API does not offer.
     *
     * An empty list in the map above is a real parity gap or a deliberate
     * decision, and the difference must be readable. A name goes here only with
     * a written reason, never as a pattern.
     *
     * @var array<int, string>
     */
    private const TOOLS_WITH_NO_REST_TWIN = [
        // Inline designs render with no template row. The REST API has no
        // endpoint that takes layers on the request; adding one is a separate
        // change with its own ApiSpec entry.
        'preview-design',
        'create-snap-from-design',
        // The package contract is MCP-only in version 1. PLAN section 5 holds
        // the contract and names no REST endpoint for it.
        'export-creative-package',
        'get-creative-package',
    ];

    /**
     * Every field the `whoami` tool returns, mapped to the REST field that
     * answers the same question.
     *
     * The map is written by hand, one line per field. A field can have a
     * different name on each surface. A matcher built on the shape of the
     * correct answer can only judge the names that already match it, so it
     * cannot see a renamed field.
     *
     * `name`, `email` and `company` come from `GET /v1/auth`.
     *
     * **This map guards one direction only.** It proves the tool answers nothing
     * REST cannot answer. It does not prove the reverse, and by its shape it
     * cannot: a field that only REST returns has no line here, so nothing looks
     * for it.
     *
     * `GET /v1/auth` catches its own extra field today, and it catches it by
     * accident. `AuthApiTest` asserts the exact body for a different reason.
     *
     * Read a green run as "the tool asks nothing REST cannot answer". Do not
     * read it as "the two surfaces agree".
     *
     * @var array<string, string>
     */
    private const WHOAMI_FIELD_TO_REST_FIELD = [
        'name' => 'name',
        'email' => 'email',
        'company' => 'company',
    ];

    /**
     * A sentinel that proves the reader returned real tools.
     *
     * A test that asserts an absence over an empty list passes in silence. If
     * the route, the token or the response shape ever changes, this assertion
     * fails first and names the cause.
     */
    private const SENTINEL_TOOL = 'create-snap';

    public function test_every_registered_tool_maps_to_an_api_spec_entry()
    {
        $tools = $this->toolNamesOverHttp();

        $this->assertContains(
            self::SENTINEL_TOOL,
            $tools,
            'The tools/list reader returned no known tool, so the tests below assert nothing.'
        );

        $unmapped = array_values(array_diff($tools, array_keys(self::TOOL_TO_ENDPOINTS)));

        $this->assertSame(
            [],
            $unmapped,
            'These tools have no mapped ApiSpec entry. Map each one, or list it in TOOLS_WITH_NO_REST_TWIN.'
        );
    }

    public function test_every_mapped_tool_is_a_tool_the_server_serves()
    {
        $stale = array_values(array_diff(array_keys(self::TOOL_TO_ENDPOINTS), $this->toolNamesOverHttp()));

        $this->assertSame(
            [],
            $stale,
            'The map names tools the server does not serve. Delete each line.'
        );
    }

    public function test_every_mapped_endpoint_is_an_api_spec_entry()
    {
        $known = array_column(ApiSpec::endpoints(), 'id');

        $this->assertNotEmpty($known, 'ApiSpec returned no endpoint, so the test below asserts nothing.');

        $unknown = [];

        foreach (self::TOOL_TO_ENDPOINTS as $tool => $endpoints) {
            foreach (array_diff($endpoints, $known) as $endpoint) {
                $unknown[] = "{$tool} -> {$endpoint}";
            }
        }

        $this->assertSame(
            [],
            $unknown,
            'The map names ApiSpec entries that do not exist.'
        );
    }

    /**
     * An empty list in the map is a parity gap. It has to be declared, so that
     * a reader can tell a decision from an oversight.
     */
    public function test_a_tool_with_no_rest_twin_is_declared()
    {
        $empty = array_keys(array_filter(self::TOOL_TO_ENDPOINTS, fn (array $endpoints) => $endpoints === []));

        $this->assertSame(
            self::TOOLS_WITH_NO_REST_TWIN,
            $empty,
            'A tool maps to no endpoint. Add its name and its reason to TOOLS_WITH_NO_REST_TWIN.'
        );
    }

    /**
     * Field-level parity for one row of the map.
     *
     * The tests above prove that a documented endpoint exists behind every
     * tool. They never read what the two surfaces return, so a tool can answer
     * a question that no endpoint answers and the build stays green. That is
     * how `company` shipped on the MCP surface with no REST home.
     *
     * A failure here means one surface grew a field the other does not carry.
     * An agent that reads the tool then cannot read the same fact over REST, or
     * the reverse, and the two faces of the product have drifted. Repair the
     * thin surface, then add the field to `WHOAMI_FIELD_TO_REST_FIELD`.
     *
     * This row is the only one checked at field level today. The other tools
     * need a fixture each, which is a larger change.
     */
    public function test_whoami_returns_no_field_the_rest_api_cannot_answer()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        // `api_requests` is the one ability both surfaces read, so one token
        // reaches the tool and the endpoint.
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        $tool = $this->mcpToolPayload($this->callMcpTool($token, 'whoami'));

        $auth = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/auth');
        $auth->assertOk();

        // `GET /v1/auth` answers `{"data": ...}`.
        $rest = $auth->json('data');

        $toolFields = array_keys($tool);
        sort($toolFields);

        $mappedFields = array_keys(self::WHOAMI_FIELD_TO_REST_FIELD);
        sort($mappedFields);

        $this->assertSame(
            $mappedFields,
            $toolFields,
            'The whoami tool and the map disagree. Tool fields with no map line: '
            .implode(', ', array_diff($toolFields, $mappedFields))
            .'. Mapped fields the tool no longer returns: '
            .implode(', ', array_diff($mappedFields, $toolFields))
        );

        $wantedRestFields = array_values(self::WHOAMI_FIELD_TO_REST_FIELD);
        sort($wantedRestFields);

        $foundRestFields = array_values(array_intersect(array_keys($rest), $wantedRestFields));
        sort($foundRestFields);

        $this->assertSame(
            $wantedRestFields,
            $foundRestFields,
            'The REST API carries no field for: '
            .implode(', ', array_diff($wantedRestFields, $foundRestFields))
            .'. Add each one to GET /v1/auth, and to ApiSpec.'
        );

        // A field with the right name and the wrong value is the same drift.
        $restValues = [];

        foreach (self::WHOAMI_FIELD_TO_REST_FIELD as $toolField => $restField) {
            $restValues[$toolField] = $rest[$restField];
        }

        ksort($restValues);
        ksort($tool);

        $this->assertSame($restValues, $tool, 'The two surfaces answer one question with two values.');
    }

    /**
     * Read the names the way a connector reads them: `tools/list` over the real
     * route, then `nextCursor` until the server stops sending one.
     *
     * @return array<int, string>
     */
    private function toolNamesOverHttp(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;

        $names = [];
        $cursor = null;
        $page = 0;

        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];

            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/mcp', [
                    'jsonrpc' => '2.0',
                    'id' => ++$page,
                    'method' => 'tools/list',
                    'params' => $params,
                ]);

            $response->assertOk();

            // A JSON-RPC error rides an HTTP 200, so `assertOk()` passes on it
            // and `array_column()` then dies on a null `result`.
            $error = $response->json('error');
            $this->assertNull($error, 'tools/list answered with a JSON-RPC error: '.json_encode($error));

            $names = array_merge($names, array_column($response->json('result.tools'), 'name'));
            $cursor = $response->json('result.nextCursor');

            // A cursor the server never clears turns this loop into a hang.
            // Ten pages hold 300 tools at the current page length.
            $this->assertLessThan(10, $page, 'tools/list kept sending a cursor. Read the paginator.');
        } while ($cursor !== null);

        $this->assertSame(
            array_unique($names),
            $names,
            'tools/list served one tool twice, so the cursor repeats a page.'
        );

        return $names;
    }
}

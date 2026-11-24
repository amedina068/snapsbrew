<?php

namespace Tests\Feature;

use App\Support\ApiDocs\OpenApiGenerator;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * `php artisan api:export` writes the committed API files in `docs/api`.
 *
 * The OpenAPI file comes from `OpenApiGenerator`. The Postman collection comes
 * from `openapi-to-postmanv2`, which the command runs with `npx`. The tests
 * fake that process and hand the command a collection with the shape that the
 * converter writes, so they read only what the command adds.
 */
class ExportApiDocsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/api-export-'.uniqid();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /**
     * CI runs the command and fails on `git diff`. This test fails earlier, in
     * the suite, when a change to `ApiSpec` leaves the committed file behind.
     */
    public function test_the_committed_spec_is_the_spec_that_the_command_writes()
    {
        $this->fakeConverter();

        $this->artisan('api:export', ['--path' => $this->directory])->assertSuccessful();

        $this->assertSame(
            file_get_contents($this->directory.'/openapi.yaml'),
            file_get_contents(base_path('docs/api/openapi.yaml')),
            'docs/api/openapi.yaml is out of date. Run `composer api-docs`.',
        );
    }

    public function test_the_spec_names_placeholder_hosts_and_no_value_of_this_instance()
    {
        config(['app.url' => 'https://images.acme.test', 'snapsbrew.support_email' => 'ops@acme.test', 'app.name' => 'Acme']);
        $this->fakeConverter();

        $this->artisan('api:export', ['--path' => $this->directory])->assertSuccessful();

        $yaml = file_get_contents($this->directory.'/openapi.yaml');

        $this->assertStringContainsString("url: 'https://app.example.com/api'", $yaml);
        $this->assertStringContainsString('email: hello@example.com', $yaml);
        $this->assertStringContainsString("title: 'Snapsbrew API'", $yaml);
        $this->assertStringNotContainsString('acme', strtolower($yaml));
    }

    public function test_the_command_runs_the_pinned_converter_on_the_written_spec()
    {
        $this->fakeConverter();

        $this->artisan('api:export', ['--path' => $this->directory])->assertSuccessful();

        Process::assertRan(function (PendingProcess $process) {
            $command = $process->command;

            return $command[0] === 'npx'
                && in_array('--package=openapi-to-postmanv2@6.4.2', $command, true)
                && $command[array_search('-s', $command, true) + 1] === $this->directory.'/openapi.yaml'
                && str_contains($command[array_search('-O', $command, true) + 1], 'folderStrategy=Tags');
        });
    }

    public function test_the_collection_sends_the_token_variable_from_the_collection_level()
    {
        $this->fakeConverter();

        $this->artisan('api:export', ['--path' => $this->directory])->assertSuccessful();

        $collection = $this->collection();

        $this->assertEquals(
            (object) ['type' => 'bearer', 'bearer' => [(object) ['key' => 'token', 'value' => '{{token}}', 'type' => 'string']]],
            $collection->auth,
        );
        $this->assertEquals([
            (object) ['key' => 'baseUrl', 'value' => 'https://app.example.com'],
            (object) ['key' => 'token', 'value' => 'YOUR_API_TOKEN'],
        ], $collection->variable);

        [$bearer, $signed] = $collection->item[0]->item;
        $this->assertObjectNotHasProperty('auth', $bearer->request, 'A bearer request inherits the collection auth.');
        $this->assertEquals((object) ['type' => 'noauth'], $signed->request->auth);
    }

    public function test_every_url_starts_at_the_instance_root()
    {
        $this->fakeConverter();

        $this->artisan('api:export', ['--path' => $this->directory])->assertSuccessful();

        [$bearer, $signed] = $this->collection()->item[0]->item;

        $this->assertSame(['{{baseUrl}}'], $bearer->request->url->host);
        $this->assertSame(['api', 'v1', 'auth'], $bearer->request->url->path);
        $this->assertSame(['api', 'v1', 'auth'], $bearer->response[0]->originalRequest->url->path);

        $this->assertSame(['{{baseUrl}}'], $signed->request->url->host);
        $this->assertSame(['signed-url', 'image.jpg'], $signed->request->url->path);
        $this->assertObjectNotHasProperty('protocol', $signed->request->url);
    }

    /**
     * The converter writes a random id on each run, so a committed file with
     * ids changes on each run and the CI diff check always fails.
     */
    public function test_the_collection_holds_no_random_id_and_keeps_empty_objects()
    {
        $this->fakeConverter();

        $this->artisan('api:export', ['--path' => $this->directory])->assertSuccessful();

        $json = file_get_contents($this->directory.'/postman_collection.json');

        $this->assertDoesNotMatchRegularExpression('/"(_postman_)?id":/', $json);
        $this->assertStringContainsString('"body": {}', $json);
        $this->assertStringNotContainsString('"event"', $json);
        $this->assertStringEndsWith("}\n", $json);
    }

    public function test_the_environment_holds_the_two_variables_with_placeholder_values()
    {
        $this->fakeConverter();

        $this->artisan('api:export', ['--path' => $this->directory])->assertSuccessful();

        $environment = json_decode(file_get_contents($this->directory.'/postman_environment.json'));

        $this->assertSame('Snapsbrew', $environment->name);
        $this->assertSame('environment', $environment->_postman_variable_scope);
        $this->assertEquals([
            (object) ['key' => 'baseUrl', 'value' => 'https://app.example.com', 'type' => 'default', 'enabled' => true],
            (object) ['key' => 'token', 'value' => 'YOUR_API_TOKEN', 'type' => 'secret', 'enabled' => true],
        ], $environment->values);
    }

    public function test_the_command_fails_when_the_converter_fails()
    {
        Process::fake(['*' => Process::result(errorOutput: 'npm error 404', exitCode: 1)]);

        $this->artisan('api:export', ['--path' => $this->directory])
            ->expectsOutputToContain('npm error 404')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->directory.'/postman_collection.json');
    }

    public function test_the_written_spec_is_the_generator_output()
    {
        $this->fakeConverter();

        $this->artisan('api:export', ['--path' => $this->directory])->assertSuccessful();

        config(['app.url' => 'https://app.example.com', 'snapsbrew.support_email' => 'hello@example.com', 'app.name' => 'Snapsbrew']);

        $this->assertSame((new OpenApiGenerator)->toYaml(), file_get_contents($this->directory.'/openapi.yaml'));
    }

    /**
     * Fake `npx` and write a collection with the shape of the converter output
     * to the `-o` path of the command.
     */
    private function fakeConverter(): void
    {
        Process::fake(function (PendingProcess $process) {
            $output = $process->command[array_search('-o', $process->command, true) + 1];

            $url = fn (array $host, array $path) => ['host' => $host, 'path' => $path, 'query' => [], 'variable' => []];

            file_put_contents($output, json_encode([
                'item' => [[
                    'name' => 'Authentication',
                    'item' => [
                        [
                            'id' => '976801ea-7f70-472a-8987-2e752d114e91',
                            'name' => 'Verify the current token',
                            'request' => [
                                'method' => 'GET',
                                'url' => $url(['{{baseUrl}}'], ['v1', 'auth']),
                                'body' => new \stdClass,
                                'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{bearerToken}}']]],
                            ],
                            'response' => [[
                                'id' => '555ed0e5-50d1-44f7-871a-2f6141ce2827',
                                'name' => 'Token is valid.',
                                'originalRequest' => ['method' => 'GET', 'url' => $url(['{{baseUrl}}'], ['v1', 'auth'])],
                            ]],
                        ],
                        [
                            'id' => '721dd92d-7f8e-4f16-9a2d-214bf02ef354',
                            'name' => 'Render a template from a signed URL',
                            'request' => [
                                'method' => 'GET',
                                'url' => ['protocol' => 'https'] + $url(['app', 'example', 'com'], ['signed-url', 'image.jpg']),
                            ],
                            'response' => [],
                        ],
                    ],
                ]],
                'event' => [],
                'variable' => [['key' => 'baseUrl', 'value' => 'https://app.example.com/api']],
                'info' => ['_postman_id' => '3717e14f-333c-4929-b3d7-0964a30464df', 'name' => 'Snapsbrew API'],
            ]));

            return Process::result();
        });
    }

    private function collection(): object
    {
        return json_decode(file_get_contents($this->directory.'/postman_collection.json'));
    }
}

<?php

namespace App\Console\Commands;

use App\Support\ApiDocs\OpenApiGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Writes the committed API files: the OpenAPI spec, the Postman collection and
 * the Postman environment.
 *
 * `ApiSpec` stays the one source. The spec comes from `OpenApiGenerator`, and
 * the collection comes from the spec through `openapi-to-postmanv2`. CI runs
 * this command and fails when `docs/api` changes, so the files cannot drift.
 */
class ExportApiDocs extends Command
{
    /** The pinned converter. A new version can change the output. */
    public const CONVERTER = 'openapi-to-postmanv2@6.4.2';

    /** The instance URL of the committed files. Each user runs their own instance. */
    private const BASE_URL = 'https://app.example.com';

    private const TOKEN = 'YOUR_API_TOKEN';

    protected $signature = 'api:export {--path=docs/api : The folder that receives the files}';

    protected $description = 'Write the OpenAPI spec and the Postman files from ApiSpec.';

    public function handle(): int
    {
        $path = $this->outputPath();

        File::ensureDirectoryExists($path);

        // The committed files must not depend on the `.env` of the machine
        // that writes them.
        config([
            'app.name' => 'Snapsbrew',
            'app.url' => self::BASE_URL,
            'snapsbrew.support_email' => 'hello@example.com',
        ]);

        File::put("{$path}/openapi.yaml", (new OpenApiGenerator)->toYaml());

        $converted = tempnam(sys_get_temp_dir(), 'postman');

        $result = Process::timeout(300)->run([
            'npx', '--yes', '--package='.self::CONVERTER, 'openapi2postmanv2',
            '-s', "{$path}/openapi.yaml",
            '-o', $converted,
            '-O', 'folderStrategy=Tags,parametersResolution=Example,includeAuthInfoInExample=false',
        ]);

        if ($result->failed()) {
            File::delete($converted);
            $this->error('The Postman converter failed.');
            $this->line($result->errorOutput() ?: $result->output());

            return self::FAILURE;
        }

        $collection = $this->collection(json_decode(File::get($converted)));
        File::delete($converted);

        File::put("{$path}/postman_collection.json", $this->json($collection));
        File::put("{$path}/postman_environment.json", $this->json($this->environment()));

        $this->info("Wrote the OpenAPI spec and the Postman files to {$path}.");

        return self::SUCCESS;
    }

    private function outputPath(): string
    {
        $path = rtrim($this->option('path'), '/');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * Give the converter output one bearer token at the collection level and
     * one `baseUrl` that is the instance root, then remove its random ids.
     */
    private function collection(object $collection): object
    {
        unset($collection->info->_postman_id, $collection->event);

        $collection->auth = (object) [
            'type' => 'bearer',
            'bearer' => [(object) ['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
        ];

        $collection->variable = [
            (object) ['key' => 'baseUrl', 'value' => self::BASE_URL],
            (object) ['key' => 'token', 'value' => self::TOKEN],
        ];

        $collection->item = array_map($this->item(...), $collection->item);

        return $collection;
    }

    private function item(object $item): object
    {
        unset($item->id);

        if (isset($item->item)) {
            $item->item = array_map($this->item(...), $item->item);

            return $item;
        }

        // A bearer request inherits the collection auth. A request with no
        // auth, for example a signed URL, must not inherit it.
        if (($item->request->auth->type ?? null) === 'bearer') {
            unset($item->request->auth);
        } else {
            $item->request->auth = (object) ['type' => 'noauth'];
        }

        $item->request->url = $this->url($item->request->url);

        foreach ($item->response ?? [] as $response) {
            unset($response->id);

            if (isset($response->originalRequest->url)) {
                $response->originalRequest->url = $this->url($response->originalRequest->url);
            }
        }

        return $item;
    }

    /**
     * The spec puts `/api` in the server URL, and the signed URL route names
     * the site root as its own server. In the collection, both start at
     * `{{baseUrl}}`, the instance root.
     */
    private function url(object $url): object
    {
        if ($url->host === ['{{baseUrl}}']) {
            $url->path = ['api', ...$url->path];
        } else {
            unset($url->protocol);
            $url->host = ['{{baseUrl}}'];
        }

        return $url;
    }

    private function environment(): object
    {
        return (object) [
            'name' => 'Snapsbrew',
            'values' => [
                (object) ['key' => 'baseUrl', 'value' => self::BASE_URL, 'type' => 'default', 'enabled' => true],
                (object) ['key' => 'token', 'value' => self::TOKEN, 'type' => 'secret', 'enabled' => true],
            ],
            '_postman_variable_scope' => 'environment',
        ];
    }

    private function json(object $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}

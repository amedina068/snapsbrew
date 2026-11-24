<?php

namespace App\Support\ApiDocs;

use Symfony\Component\Yaml\Yaml;

/**
 * Builds an OpenAPI 3.0 document from {@see ApiSpec}. The app serves it on the
 * fly, and `php artisan api:export` writes it to `docs/api/openapi.yaml`.
 *
 * Schemas come from three places in ApiSpec:
 *   - `ApiSpec::schemas()`: a component per entry. A field type names a scalar
 *     (`string`, `integer`, `boolean`, `datetime`), a component (`Layer`), a
 *     list of either (`Layer[]`, `integer[]`), and `|null` makes it nullable.
 *     A field `schema` or a component `openapi` key replaces the derived schema.
 *   - a body field: the same type names, plus an `items` schema for an array.
 *   - a response `schema`: an OpenAPI schema. A `$ref` may use the short name
 *     of a component, for example `['$ref' => 'Snap']`.
 */
class OpenApiGenerator
{
    private const RATE_LIMIT_HEADERS = [
        'Retry-After' => ['description' => 'Seconds to wait before the next request.', 'schema' => ['type' => 'integer', 'example' => 30]],
        'X-RateLimit-Limit' => ['description' => 'Requests allowed in the current minute.', 'schema' => ['type' => 'integer', 'example' => 60]],
        'X-RateLimit-Remaining' => ['description' => 'Requests left in the current minute.', 'schema' => ['type' => 'integer', 'example' => 0]],
    ];

    /** Return the OpenAPI document as a PHP array. */
    public function toArray(): array
    {
        $info = ApiSpec::info();

        $doc = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $info['title'],
                'description' => $info['description']."\n\n".$info['rateLimits'],
                'version' => $info['version'],
                'contact' => ['email' => $info['contactEmail']],
            ],
            'servers' => [
                ['url' => $info['baseUrl'], 'description' => 'Production'],
            ],
            'tags' => [],
            'paths' => [],
            'components' => [
                'securitySchemes' => [
                    'bearerToken' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Sanctum personal access token with the api_requests ability.',
                    ],
                ],
                'schemas' => $this->componentSchemas(),
            ],
        ];

        foreach (ApiSpec::groups() as $group) {
            $doc['tags'][] = [
                'name' => $group['title'],
                'description' => $this->oneLine($group['description']),
            ];

            foreach ($group['endpoints'] as $endpoint) {
                $path = $this->normalisePath($endpoint['path']);
                $method = strtolower($endpoint['method']);
                $doc['paths'][$path][$method] = $this->operation($endpoint, $group['title'], $info);
            }
        }

        return $doc;
    }

    /** Return the OpenAPI document as YAML. */
    public function toYaml(): string
    {
        // An empty example object is `{}` and an empty list is `[]`, not null.
        return Yaml::dump($this->toArray(), 20, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    private function operation(array $endpoint, string $tag, array $info): array
    {
        $operation = [
            'tags' => [$tag],
            'operationId' => $this->operationId($endpoint['id']),
            'summary' => $endpoint['summary'],
            'description' => $this->descriptionWithMeta($endpoint),
        ];

        // A route outside `/api` names its own server, so a client builds the
        // URL from the site root and not from the API base.
        if (($endpoint['base'] ?? 'api') === 'site') {
            $operation['servers'] = [['url' => $info['siteUrl']]];
        }

        if ($endpoint['auth'] === 'bearer') {
            $operation['security'] = [['bearerToken' => []]];
        }

        $params = [];
        foreach ($endpoint['parameters'] ?? [] as $param) {
            $parameter = [
                'name' => $param['name'],
                'in' => $param['in'],
                'required' => (bool) ($param['required'] ?? false),
                'description' => $param['description'] ?? '',
                'schema' => $this->schemaOf($param),
            ];
            foreach (['style', 'explode', 'example'] as $key) {
                if (array_key_exists($key, $param)) {
                    $parameter[$key] = $param[$key];
                }
            }
            $params[] = $parameter;
        }
        if ($params) {
            $operation['parameters'] = $params;
        }

        if (! empty($endpoint['body'])) {
            $operation['requestBody'] = $this->requestBody($endpoint['body'], $this->curlBody($endpoint));
        }

        $operation['responses'] = $this->responses($endpoint['responses'] ?? [], $endpoint['auth'] === 'bearer');

        return $operation;
    }

    private function requestBody(array $body, ?\stdClass $example): array
    {
        $properties = [];
        $required = [];

        foreach ($body['fields'] as $field) {
            // A nested row (`items[].variants`, `tag_ids.*`) is there for the
            // documentation screen. The parent field carries its schema.
            if (str_contains($field['name'], '[].') || str_ends_with($field['name'], '.*')) {
                continue;
            }

            $name = $field['name'];

            $schema = $this->schemaOf($field);
            $schema['description'] = trim(($field['description'] ?? '').' Rules: '.($field['rules'] ?? 'none').'.');

            $properties[$name] = $schema;
            if (! empty($field['required'])) {
                $required[] = $name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required) {
            $schema['required'] = $required;
        }

        $media = ['schema' => $schema];
        if ($example !== null) {
            $media['example'] = $example;
        }

        return [
            'required' => (bool) $required,
            'content' => [
                $body['contentType'] => $media,
            ],
        ];
    }

    /**
     * The JSON body of the curl example. The request example reads it, so the
     * two cannot drift, and a client that fakes a body from the schema gets
     * the same body on each run. It decodes to objects, because a PHP array
     * cannot tell the JSON object `{}` from the JSON list `[]`.
     */
    private function curlBody(array $endpoint): ?\stdClass
    {
        if (! preg_match("/ -d '(.+)'\\s*\$/s", $endpoint['examples']['curl'] ?? '', $match)) {
            return null;
        }

        $body = json_decode($match[1]);

        return $body instanceof \stdClass ? $body : null;
    }

    private function responses(array $responses, bool $rateLimited): array
    {
        $out = [];
        foreach ($responses as $response) {
            $out[(string) $response['status']] = $this->response($response);
        }

        // Every bearer route sits in the throttled `api` middleware group.
        if ($rateLimited && ! isset($out['429'])) {
            $out['429'] = $this->response([
                'status' => 429,
                'description' => 'Rate limit exceeded. Wait the number of seconds in `Retry-After`, then send the request again.',
                'example' => ['message' => 'Too Many Attempts.'],
            ]);
        }

        ksort($out);

        return $out ?: ['200' => ['description' => 'OK']];
    }

    private function response(array $response): array
    {
        $out = ['description' => $response['description']];

        $headers = $response['headers'] ?? [];
        if ((int) $response['status'] === 429) {
            $headers += self::RATE_LIMIT_HEADERS;
        }
        if ($headers) {
            $out['headers'] = $headers;
        }

        // `contentType: null` documents a response with no body, for example a
        // redirect.
        $contentType = array_key_exists('contentType', $response) ? $response['contentType'] : 'application/json';
        if ($contentType === null) {
            return $out;
        }

        $media = [];
        $schema = $response['schema'] ?? $this->errorSchema($response);
        if ($schema !== null) {
            $media['schema'] = $this->expandRefs($schema);
        }
        if (array_key_exists('example', $response) || $contentType === 'application/json') {
            $media['example'] = $response['example'] ?? new \stdClass;
        }

        $out['content'] = [$contentType => $media];

        return $out;
    }

    /**
     * The shared schema of an error body that names none: `Error` for a
     * message only, `ValidationError` for a message with field errors.
     */
    private function errorSchema(array $response): ?array
    {
        if ((int) $response['status'] < 400 || ! is_array($response['example'] ?? null)) {
            return null;
        }

        return match (array_keys($response['example'])) {
            ['message'] => ['$ref' => 'Error'],
            ['message', 'errors'] => ['$ref' => 'ValidationError'],
            default => null,
        };
    }

    private function componentSchemas(): array
    {
        $out = [];
        foreach (ApiSpec::schemas() as $name => $schema) {
            if (isset($schema['openapi'])) {
                $out[$name] = ['description' => $schema['description']] + $this->expandRefs($schema['openapi']);

                continue;
            }

            $properties = [];
            $required = [];
            foreach ($schema['fields'] as $field) {
                $properties[$field['name']] = $this->schemaOf($field) + ['description' => $field['description']];
                if (! empty($field['required'])) {
                    $required[] = $field['name'];
                }
            }

            $out[$name] = [
                'type' => 'object',
                'description' => $schema['description'],
                'properties' => $properties,
            ];
            if ($required) {
                $out[$name]['required'] = $required;
            }
            if (array_key_exists('additionalProperties', $schema)) {
                $out[$name]['additionalProperties'] = $schema['additionalProperties'];
            }
        }

        return $out;
    }

    /**
     * The schema of a field, a parameter or a component property.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private function schemaOf(array $field): array
    {
        if (isset($field['schema'])) {
            return $this->expandRefs($field['schema']);
        }

        $schema = $this->schemaForType($field['type']);

        if (isset($field['items'])) {
            $schema['items'] = $this->expandRefs($field['items']);
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForType(string $type): array
    {
        $parts = array_map('trim', explode('|', $type));
        $nullable = in_array('null', $parts, true);
        $type = array_values(array_diff($parts, ['null']))[0] ?? 'string';

        if (str_ends_with($type, '[]')) {
            $schema = ['type' => 'array', 'items' => $this->schemaForType(substr($type, 0, -2))];
        } elseif (array_key_exists($type, ApiSpec::schemas())) {
            $schema = ['$ref' => '#/components/schemas/'.$type];
        } else {
            $schema = match ($type) {
                'integer' => ['type' => 'integer'],
                'boolean' => ['type' => 'boolean'],
                'datetime' => ['type' => 'string', 'format' => 'date-time'],
                'object' => ['type' => 'object'],
                'file' => ['type' => 'string', 'format' => 'binary'],
                // An array with no declared item type holds strings. A field
                // that holds objects names its `items` schema.
                'array' => ['type' => 'array', 'items' => ['type' => 'string']],
                default => ['type' => 'string'],
            };
        }

        if ($nullable) {
            // OpenAPI 3.0 ignores a sibling of `$ref`, so wrap the reference.
            $schema = isset($schema['$ref']) ? ['allOf' => [$schema], 'nullable' => true] : $schema + ['nullable' => true];
        }

        return $schema;
    }

    /**
     * Turn a short `$ref` (`Snap`) into a full component reference.
     */
    private function expandRefs(mixed $schema): mixed
    {
        if (! is_array($schema)) {
            return $schema;
        }

        foreach ($schema as $key => $value) {
            if ($key === '$ref' && is_string($value) && ! str_starts_with($value, '#')) {
                $schema[$key] = '#/components/schemas/'.$value;
            } elseif (is_array($value)) {
                $schema[$key] = $this->expandRefs($value);
            }
        }

        return $schema;
    }

    private function descriptionWithMeta(array $endpoint): string
    {
        return EndpointDescription::plainText($endpoint);
    }

    private function operationId(string $id): string
    {
        return str_replace(['.', '-'], ['_', '_'], $id);
    }

    private function normalisePath(string $path): string
    {
        // ApiSpec paths are relative to /api; OpenAPI servers add the rest.
        return '/'.ltrim($path, '/');
    }

    private function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}

<?php

namespace App\Support\ApiDocs;

/**
 * Builds a Postman v2.1 collection from {@see ApiSpec}. Output is produced on
 * the fly. Variables: {{base_url}} and {{token}} resolve from the collection.
 */
class PostmanGenerator
{
    /** Return the Postman collection as a PHP array. */
    public function toArray(): array
    {
        $info = ApiSpec::info();

        $items = [];
        foreach (ApiSpec::groups() as $group) {
            $items[] = [
                'name' => $group['title'],
                'description' => $this->oneLine($group['description']),
                'item' => array_map(
                    fn (array $endpoint) => $this->request($endpoint),
                    $group['endpoints']
                ),
            ];
        }

        return [
            'info' => [
                'name' => $info['title'].' Suite',
                'description' => $info['description'],
                '_postman_id' => $this->stableUuid($info['title']),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => $items,
            'auth' => [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '{{token}}', 'type' => 'string']],
            ],
            'variable' => [
                ['key' => 'base_url', 'value' => $info['baseUrl'], 'type' => 'string'],
                ['key' => 'site_url', 'value' => $info['siteUrl'], 'type' => 'string'],
                ['key' => 'token', 'value' => 'YOUR_API_TOKEN', 'type' => 'string'],
            ],
        ];
    }

    /** Return the collection as pretty-printed JSON. */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function request(array $endpoint): array
    {
        $headers = [['key' => 'Accept', 'value' => 'application/json']];
        $request = [
            'method' => $endpoint['method'],
            'header' => $headers,
            'url' => $this->url($endpoint),
            'description' => EndpointDescription::plainText($endpoint),
        ];

        if ($endpoint['auth'] !== 'bearer') {
            $request['auth'] = ['type' => 'noauth'];
        }

        if (! empty($endpoint['body'])) {
            $this->applyBody($request, $endpoint['body']);
        }

        return [
            'name' => $endpoint['summary'],
            'request' => $request,
            'event' => [$this->statusTest($endpoint)],
            'response' => [],
        ];
    }

    private function applyBody(array &$request, array $body): void
    {
        if ($body['contentType'] === 'multipart/form-data') {
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'multipart/form-data', 'disabled' => true];
            $request['body'] = [
                'mode' => 'formdata',
                'formdata' => array_map(function (array $field) {
                    return [
                        'key' => $field['name'],
                        'type' => $field['type'] === 'file' ? 'file' : 'text',
                        'value' => $field['type'] === 'file' ? '' : '',
                        'description' => $field['description'] ?? '',
                    ];
                }, $this->topLevelFields($body['fields'])),
            ];

            return;
        }

        $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
        $request['body'] = [
            'mode' => 'raw',
            'raw' => $this->sampleBody($body['fields']),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    private function sampleBody(array $fields): string
    {
        $sample = [];
        foreach ($this->topLevelFields($fields) as $field) {
            $sample[$field['name']] = $this->sampleValue($field);
        }

        return json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function sampleValue(array $field)
    {
        $type = $field['type'];
        if (str_ends_with($type, '[]') || $type === 'array') {
            return [];
        }

        return match ($type) {
            'integer' => 0,
            'boolean' => false,
            'object' => new \stdClass,
            default => '<'.$field['name'].'>',
        };
    }

    private function topLevelFields(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn (array $field) => ! str_contains($field['name'], '.')
        ));
    }

    private function url(array $endpoint): array
    {
        $path = ltrim($endpoint['path'], '/');
        $segments = explode('/', $path);
        // A route outside `/api` starts at the site root.
        $host = ($endpoint['base'] ?? 'api') === 'site' ? '{{site_url}}' : '{{base_url}}';

        $url = [
            'raw' => $host.'/'.$path,
            'host' => [$host],
            'path' => $segments,
        ];

        $query = [];
        foreach ($endpoint['parameters'] ?? [] as $param) {
            if (($param['in'] ?? null) === 'query') {
                $query[] = [
                    'key' => $param['name'],
                    'value' => '',
                    'description' => $param['description'] ?? '',
                    'disabled' => ! ($param['required'] ?? false),
                ];
            }
        }
        if ($query) {
            $url['query'] = $query;
        }

        return $url;
    }

    private function statusTest(array $endpoint): array
    {
        $statuses = array_map(
            fn (array $response) => (int) $response['status'],
            $endpoint['responses'] ?? []
        );
        $okStatuses = array_values(array_filter($statuses, fn (int $code) => $code < 300)) ?: [200];

        $list = implode(', ', $okStatuses);

        return [
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    "pm.test('Successful response', function () {",
                    "  pm.expect([{$list}]).to.include(pm.response.code);",
                    '});',
                ],
            ],
        ];
    }

    private function stableUuid(string $seed): string
    {
        $hash = md5('snapsbrew-postman-'.$seed);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    private function oneLine(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}

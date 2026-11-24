<?php

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Read the OpenAPI document that the app serves, and check a value against a
 * schema in it.
 *
 * The checker covers the subset of OpenAPI 3.0 that `OpenApiGenerator` writes:
 * `$ref`, `oneOf`, `allOf`, `nullable`, `type`, `enum`, `required`,
 * `properties`, `additionalProperties: false`, `minProperties` and `items`. A
 * keyword outside that subset fails the check, so a new keyword cannot pass
 * without a rule.
 *
 * Pass the raw body. The checker decodes it to objects, because a PHP array
 * cannot tell the JSON object `{}` from the JSON list `[]`.
 */
trait ReadsOpenApiDocument
{
    private ?array $openApiDocument = null;

    /**
     * The document as a client downloads it: the YAML route, parsed.
     *
     * @return array<string, mixed>
     */
    protected function openApi(): array
    {
        if ($this->openApiDocument === null) {
            $yaml = $this->get('/documentation/openapi.yaml')->assertOk()->getContent();
            $this->openApiDocument = Yaml::parse($yaml);
        }

        return $this->openApiDocument;
    }

    /**
     * @return array<string, mixed>
     */
    protected function operation(string $method, string $path): array
    {
        $operation = $this->openApi()['paths'][$path][strtolower($method)] ?? null;

        $this->assertNotNull($operation, "The OpenAPI document has no {$method} {$path}.");

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseSchema(string $method, string $path, int $status, string $contentType = 'application/json'): array
    {
        $response = $this->operation($method, $path)['responses'][(string) $status] ?? null;

        $this->assertNotNull($response, "{$method} {$path} documents no {$status} response.");

        $schema = $response['content'][$contentType]['schema'] ?? null;

        $this->assertNotNull($schema, "{$method} {$path} {$status} documents no {$contentType} schema.");

        return $schema;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function resolveSchema(array $schema): array
    {
        while (isset($schema['$ref'])) {
            $name = substr($schema['$ref'], strlen('#/components/schemas/'));
            $resolved = $this->openApi()['components']['schemas'][$name] ?? null;

            $this->assertNotNull($resolved, "The reference {$schema['$ref']} names no schema.");

            $schema = $resolved;
        }

        return $schema;
    }

    /**
     * Fail when the JSON body does not match the schema. Every mismatch names
     * the JSON path, so a failure says which field the document gets wrong.
     *
     * @param  array<string, mixed>  $schema
     */
    protected function assertMatchesSchema(string $json, array $schema): void
    {
        $errors = $this->schemaErrors(json_decode($json, false, 512, JSON_THROW_ON_ERROR), $schema, '$');

        $this->assertSame([], $errors, "The response does not match the documented schema:\n".implode("\n", $errors));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private function schemaErrors(mixed $value, array $schema, string $path): array
    {
        $schema = $this->resolveSchema($schema);

        $known = ['type', 'nullable', 'properties', 'required', 'items', 'oneOf', 'allOf', 'enum', 'additionalProperties',
            'minProperties', 'description', 'format', 'example', 'minItems', 'maxItems', 'maxLength', 'pattern'];
        $unknown = array_diff(array_keys($schema), $known);
        if ($unknown) {
            return ["{$path}: the checker has no rule for ".implode(', ', $unknown).'.'];
        }

        if ($value === null) {
            return ($schema['nullable'] ?? false) ? [] : ["{$path}: null, but the schema is not nullable."];
        }

        if (isset($schema['allOf'])) {
            $errors = [];
            foreach ($schema['allOf'] as $branch) {
                array_push($errors, ...$this->schemaErrors($value, $branch, $path));
            }

            return $errors;
        }

        if (isset($schema['oneOf'])) {
            $matches = 0;
            $branchErrors = [];
            foreach ($schema['oneOf'] as $index => $branch) {
                $errors = $this->schemaErrors($value, $branch, $path);
                $errors ? $branchErrors[] = "branch {$index}: ".implode('; ', $errors) : $matches++;
            }

            return $matches === 1 ? [] : ["{$path}: matches {$matches} oneOf branches, not 1. ".implode(' | ', $branchErrors)];
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return ["{$path}: ".json_encode($value).' is not one of '.json_encode($schema['enum']).'.'];
        }

        $type = $schema['type'] ?? null;
        $actual = match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            $value instanceof \stdClass => 'object',
            default => get_debug_type($value),
        };

        if ($type !== null && $type !== $actual && ! ($type === 'number' && $actual === 'integer')) {
            return ["{$path}: the schema says {$type}, the response holds {$actual}."];
        }

        $errors = [];

        if ($actual === 'array') {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = "{$path}: more than {$schema['maxItems']} items.";
            }

            foreach (isset($schema['items']) ? $value : [] as $index => $item) {
                array_push($errors, ...$this->schemaErrors($item, $schema['items'], "{$path}[{$index}]"));
            }
        }

        if ($actual === 'object') {
            $value = get_object_vars($value);

            if (isset($schema['minProperties']) && count($value) < $schema['minProperties']) {
                $errors[] = "{$path}: fewer than {$schema['minProperties']} properties.";
            }

            foreach ($schema['required'] ?? [] as $name) {
                if (! array_key_exists($name, $value)) {
                    $errors[] = "{$path}.{$name}: required, but the response does not hold it.";
                }
            }

            foreach ($value as $name => $item) {
                if (isset($schema['properties'][$name])) {
                    array_push($errors, ...$this->schemaErrors($item, $schema['properties'][$name], "{$path}.{$name}"));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$name}: the schema does not document this field.";
                }
            }
        }

        return $errors;
    }
}

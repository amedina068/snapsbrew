<?php

namespace Tests\Feature\Api;

use App\Support\VariantRules;
use cebe\openapi\Reader;
use JsonSchema\Validator;
use Tests\Support\ReadsOpenApiDocument;
use Tests\TestCase;

/**
 * The OpenAPI document that the app serves is valid OpenAPI 3.0, and it says
 * what the code does.
 *
 * Four SDKs were built from this document and each one found the same
 * differences with the code. Each test below pins one of those differences, so
 * the document cannot drift back.
 */
class OpenApiSpecTest extends TestCase
{
    use ReadsOpenApiDocument;

    // ------------------------------------------------------------ validity

    public function test_the_served_document_passes_the_openapi_reader_validation()
    {
        $yaml = $this->get('/documentation/openapi.yaml')->assertOk()->getContent();

        $document = Reader::readFromYaml($yaml);

        $this->assertTrue($document->validate(), implode("\n", $document->getErrors()));
        $this->assertSame('3.0.3', $document->openapi);
    }

    /**
     * The official JSON Schema for OpenAPI 3.0 (2021-09-28) is stricter than
     * the reader. It rejects an unknown key in most objects.
     */
    public function test_the_served_document_matches_the_official_openapi_3_0_json_schema()
    {
        $document = json_decode(json_encode($this->openApi()));
        $schema = json_decode(file_get_contents(base_path('tests/Fixtures/openapi-3.0-schema.json')));

        $validator = new Validator;
        $validator->validate($document, $schema);

        $errors = array_map(fn (array $error) => $error['property'].': '.$error['message'], $validator->getErrors());

        $this->assertTrue($validator->isValid(), implode("\n", $errors));
    }

    /**
     * Neither validator catches an array with no `items` or a `$ref` that names
     * no schema. A generated client cannot type either one.
     */
    public function test_every_array_has_items_and_every_reference_resolves()
    {
        $problems = [];
        $schemas = $this->openApi()['components']['schemas'];

        $walk = function (mixed $node, string $path) use (&$walk, &$problems, $schemas) {
            if (! is_array($node)) {
                return;
            }

            if (($node['type'] ?? null) === 'array' && ! isset($node['items'])) {
                $problems[] = "{$path}: an array with no items.";
            }

            if (isset($node['$ref']) && is_string($node['$ref'])) {
                $name = substr($node['$ref'], strlen('#/components/schemas/'));
                if (! isset($schemas[$name])) {
                    $problems[] = "{$path}: {$node['$ref']} names no schema.";
                }
            }

            foreach ($node as $key => $child) {
                $walk($child, "{$path}.{$key}");
            }
        };

        $walk($this->openApi(), '$');

        $this->assertSame([], $problems);
    }

    // ----------------------------------------------------- request bodies

    public function test_the_variant_schema_is_a_one_of_of_every_property_that_variant_rules_accepts()
    {
        $variant = $this->openApi()['components']['schemas']['Variant'];

        $accepted = [];
        foreach (array_keys(VariantRules::rules()) as $rule) {
            if (str_starts_with($rule, 'variants.*.') && $rule !== 'variants.*.layer') {
                $accepted[] = substr($rule, strlen('variants.*.'));
            }
        }

        $branches = [];
        foreach ($variant['oneOf'] as $branch) {
            $this->assertSame('object', $branch['type']);
            $this->assertContains('layer', $branch['required']);
            $override = array_values(array_diff(array_keys($branch['properties']), ['layer']));
            $this->assertCount(1, $override, 'Each branch holds the layer key and one override.');
            $branches[] = $override[0];
        }

        sort($accepted);
        sort($branches);
        $this->assertSame($accepted, $branches);
        $this->assertContains('barcode_content', $branches);
    }

    public function test_variants_items_and_layers_are_arrays_of_objects_and_tag_ids_are_integers()
    {
        $body = fn (string $method, string $path) => $this->operation($method, $path)['requestBody']['content']['application/json']['schema']['properties'];

        $this->assertSame('#/components/schemas/Variant', $body('POST', '/v1/snaps')['variants']['items']['$ref']);

        $items = $body('POST', '/v1/snaps/batches')['items'];
        $this->assertSame('object', $items['items']['type']);
        $this->assertSame('#/components/schemas/Variant', $items['items']['properties']['variants']['items']['$ref']);
        $this->assertSame('string', $items['items']['properties']['identifier']['type']);
        $this->assertArrayNotHasKey('items[].variants', $body('POST', '/v1/snaps/batches'));

        foreach ([['POST', '/v1/templates'], ['PATCH', '/v1/templates/{templateIdentifier}']] as [$method, $path]) {
            $layers = $this->resolveSchema($body($method, $path)['layers']['items']);
            $this->assertSame('object', $layers['type']);
            $this->assertSame(['key', 'properties'], $layers['required']);
        }

        $this->assertSame('integer', $body('POST', '/v1/templates/{templateUuid}/tags')['tag_ids']['items']['type']);
        $this->assertSame('object', $body('PATCH', '/v1/templates/{templateUuid}/document')['operations']['items']['type']);
    }

    // ---------------------------------------------------------- responses

    public function test_no_text_claims_that_every_success_body_is_wrapped_in_data()
    {
        $yaml = $this->get('/documentation/openapi.yaml')->getContent();

        $this->assertStringNotContainsString('Every success body of this API is', $yaml);
        $this->assertStringNotContainsString('Every other success body of this API holds one', $yaml);

        foreach ([['POST', '/v1/snaps', 201], ['POST', '/v1/snaps/batches', 202], ['GET', '/v1/snaps/batches/{batchUuid}', 200], ['POST', '/v1/media', 200]] as [$method, $path, $status]) {
            $schema = $this->resolveSchema($this->responseSchema($method, $path, $status));
            $this->assertArrayNotHasKey('data', $schema['properties'] ?? [], "{$method} {$path} has no data wrapper.");
        }
    }

    public function test_the_snap_schema_has_width_height_and_nullable_variants()
    {
        $snap = $this->openApi()['components']['schemas']['Snap'];

        $this->assertSame('integer', $snap['properties']['width']['type']);
        $this->assertSame('integer', $snap['properties']['height']['type']);
        $this->assertSame('array', $snap['properties']['variants']['type']);
        $this->assertTrue($snap['properties']['variants']['nullable']);
    }

    public function test_a_template_layer_has_no_type_and_documents_its_modifications()
    {
        $layer = $this->openApi()['components']['schemas']['Layer'];

        $this->assertSame(['layer', 'available_modifications'], array_keys($layer['properties']));
        $this->assertArrayHasKey('oneOf', $layer['properties']['available_modifications']);
    }

    public function test_the_screenshot_list_uses_uuid_and_the_read_uses_id()
    {
        $list = $this->resolveSchema($this->responseSchema('GET', '/v1/screenshots', 200));
        $item = $this->resolveSchema($list['properties']['data']['items']);
        $this->assertArrayHasKey('uuid', $item['properties']);
        $this->assertArrayNotHasKey('id', $item['properties']);

        foreach ([['GET', '/v1/screenshots/{screenshotUuid}'], ['POST', '/v1/screenshots']] as [$method, $path]) {
            $read = $this->resolveSchema($this->resolveSchema($this->responseSchema($method, $path, 200))['properties']['data']);
            $this->assertArrayHasKey('id', $read['properties']);
            $this->assertArrayNotHasKey('uuid', $read['properties']);
        }
    }

    public function test_the_media_list_is_a_flat_laravel_paginator()
    {
        $list = $this->resolveSchema($this->responseSchema('GET', '/v1/media', 200));

        foreach (['current_page', 'data', 'first_page_url', 'from', 'last_page', 'last_page_url', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to', 'total'] as $key) {
            $this->assertArrayHasKey($key, $list['properties']);
        }

        $this->assertArrayNotHasKey('meta', $list['properties']);
    }

    public function test_a_preview_returns_text_html()
    {
        foreach ([['GET', '/v1/templates/{templateUuid}/preview'], ['GET', '/v1/snaps/preview']] as [$method, $path]) {
            $content = $this->operation($method, $path)['responses']['200']['content'];
            $this->assertSame(['text/html'], array_keys($content));
        }
    }

    public function test_a_template_update_documents_the_v2_conflict()
    {
        $schema = $this->resolveSchema($this->responseSchema('PATCH', '/v1/templates/{templateIdentifier}', 409));

        $this->assertSame(['legacy_edit_rejected_for_v2'], $schema['properties']['code']['enum']);
    }

    public function test_snap_and_batch_validation_errors_document_the_errors_field()
    {
        foreach ([['POST', '/v1/snaps'], ['POST', '/v1/snaps/batches']] as [$method, $path]) {
            $schema = $this->resolveSchema($this->responseSchema($method, $path, 422));
            $this->assertContains('errors', $schema['required']);
        }
    }

    public function test_an_idempotency_conflict_documents_its_stable_code()
    {
        $snap = $this->resolveSchema($this->responseSchema('POST', '/v1/snaps', 409));
        $this->assertSame(['idempotency_key_in_progress', 'idempotency_key_belongs_to_batch'], $snap['properties']['code']['enum']);

        $batch = $this->resolveSchema($this->responseSchema('POST', '/v1/snaps/batches', 409));
        $this->assertSame(['idempotency_key_in_progress', 'idempotency_key_belongs_to_snap'], $batch['properties']['code']['enum']);
    }

    public function test_an_unexpected_snap_or_batch_error_documents_the_internal_error_code()
    {
        foreach ([['POST', '/v1/snaps'], ['POST', '/v1/snaps/batches']] as [$method, $path]) {
            $schema = $this->resolveSchema($this->responseSchema($method, $path, 500));

            $this->assertSame(['message', 'code'], $schema['required']);
            $this->assertSame(['internal_error'], $schema['properties']['code']['enum']);
        }
    }

    public function test_an_unknown_template_id_is_documented_as_a_422()
    {
        $description = $this->operation('POST', '/v1/snaps')['responses']['422']['description'];

        $this->assertStringContainsString('template_id', $description);
    }

    // -------------------------------------------------------- rate limits

    public function test_the_rate_limits_are_documented_with_the_values_the_code_uses()
    {
        $info = $this->openApi()['info']['description'];
        $this->assertStringContainsString('60 requests per minute', $info);
        $this->assertStringContainsString('10 requests per minute', $info);

        $preview = $this->operation('GET', '/v1/snaps/preview')['responses']['429'];
        $this->assertArrayHasKey('Retry-After', $preview['headers']);

        // Every bearer endpoint sits in the throttled api group.
        foreach ($this->openApi()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (isset($operation['security'])) {
                    $this->assertArrayHasKey('429', $operation['responses'], strtoupper($method)." {$path} documents no 429.");
                }
            }
        }
    }

    // ---------------------------------------------------------- signed URL

    public function test_the_signed_url_route_is_documented_on_the_site_root()
    {
        $operation = $this->operation('GET', '/signed-url/image.jpg');

        $this->assertSame(rtrim(config('app.url'), '/'), $operation['servers'][0]['url']);
        $this->assertArrayNotHasKey('security', $operation);

        $parameters = collect($operation['parameters'])->keyBy('name');
        $this->assertTrue($parameters['template_id']['required']);
        $this->assertTrue($parameters['signature']['required']);
        $this->assertSame(['png', 'jpeg', 'webp'], $parameters['format']['schema']['enum']);
        $this->assertArrayHasKey('transparent', $parameters->all());
        $this->assertStringContainsString('standard base64', $parameters['variants']['description']);

        $this->assertStringContainsString('HMAC-SHA256', $operation['description']);
        $this->assertArrayHasKey('image/png', $operation['responses']['200']['content']);
        $this->assertStringContainsString('signature', $operation['responses']['200']['description']);
        $this->assertArrayHasKey('302', $operation['responses']);
    }
    // ---------------------------------------------------- error responses

    /**
     * An SDK maps each status to a typed error. A status that the document
     * leaves out has no type, so a client cannot plan for it.
     */
    public function test_every_operation_has_a_summary_and_a_description()
    {
        foreach ($this->operations() as $name => $operation) {
            $this->assertNotSame('', trim($operation['summary'] ?? ''), "{$name} has no summary.");
            $this->assertNotSame('', trim($operation['description'] ?? ''), "{$name} has no description.");
        }
    }

    /**
     * Every bearer route sits behind `auth:sanctum`, `abilities:api_requests`
     * and a throttle, so each one can answer 401, 403 and 429.
     */
    public function test_every_bearer_operation_documents_401_403_and_429()
    {
        foreach ($this->operations() as $name => $operation) {
            if (! isset($operation['security'])) {
                continue;
            }

            $this->assertSame([['bearerToken' => []]], $operation['security'], "{$name} names no bearer scheme.");

            foreach (['401', '403', '429'] as $status) {
                $this->assertArrayHasKey($status, $operation['responses'], "{$name} documents no {$status}.");
            }
        }
    }

    public function test_every_operation_with_a_path_parameter_documents_404()
    {
        foreach ($this->operations() as $name => $operation) {
            if (str_contains($name, '{')) {
                $this->assertArrayHasKey('404', $operation['responses'], "{$name} documents no 404.");
            }
        }
    }

    public function test_every_operation_with_a_request_body_documents_422()
    {
        foreach ($this->operations() as $name => $operation) {
            if (isset($operation['requestBody'])) {
                $this->assertArrayHasKey('422', $operation['responses'], "{$name} documents no 422.");
            }
        }
    }

    /**
     * These list routes call `$request->validate()` on the query string.
     */
    public function test_a_list_that_validates_its_query_documents_422()
    {
        foreach (['/v1/templates', '/v1/screenshots'] as $path) {
            $this->assertArrayHasKey('422', $this->operation('GET', $path)['responses'], "GET {$path} documents no 422.");
        }
    }

    /**
     * The signed URL route and the embedded form route have no token, but each
     * one has its own throttle.
     */
    public function test_the_routes_without_a_token_document_their_throttle()
    {
        foreach ([['GET', '/signed-url/image.jpg'], ['POST', '/v1/embedded-forms/{formUuid}']] as [$method, $path]) {
            $response = $this->operation($method, $path)['responses']['429'] ?? null;

            $this->assertNotNull($response, "{$method} {$path} documents no 429.");
            $this->assertArrayHasKey('Retry-After', $response['headers']);
        }
    }

    public function test_every_json_error_response_has_a_schema()
    {
        foreach ($this->operations() as $name => $operation) {
            foreach ($operation['responses'] as $status => $response) {
                if ((int) $status < 400 || ! isset($response['content']['application/json'])) {
                    continue;
                }

                $this->assertArrayHasKey('schema', $response['content']['application/json'], "{$name} {$status} has no schema.");
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function operations(): array
    {
        $out = [];

        foreach ($this->openApi()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $out[strtoupper($method).' '.$path] = $operation;
            }
        }

        $this->assertArrayHasKey('POST /v1/snaps', $out, 'The operation reader returned no known operation.');

        return $out;
    }
}

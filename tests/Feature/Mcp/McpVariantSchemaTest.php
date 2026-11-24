<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SnapsbrewServer;
use App\Support\VariantProperties;
use App\Support\VariantRules;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Every MCP surface that describes a variant must name every property that
 * `VariantRules` accepts.
 *
 * The `variants` schema once listed `qr_content` and not `barcode_content`,
 * although the validation accepted both. An agent that reads only the schema
 * could not find the barcode override.
 */
class McpVariantSchemaTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function acceptedProperties(): array
    {
        $properties = [];

        foreach (array_keys(VariantRules::rules()) as $rule) {
            if (str_starts_with($rule, 'variants.*.')) {
                $properties[] = substr($rule, strlen('variants.*.'));
            }
        }

        sort($properties);

        return $properties;
    }

    /**
     * Find every property named `variants` in a JSON schema, at any depth.
     *
     * @return list<array<string, mixed>>
     */
    private function variantSchemas(array $schema): array
    {
        $found = [];

        foreach ($schema as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            if ($key === 'variants' && ($value['type'] ?? null) === 'array') {
                $found[] = $value;
            }

            array_push($found, ...$this->variantSchemas($value));
        }

        return $found;
    }

    /**
     * @return array<class-string, array<string, mixed>>
     */
    private function toolSchemas(): array
    {
        $tools = (new ReflectionProperty(SnapsbrewServer::class, 'tools'))->getDefaultValue();

        $schemas = [];
        foreach ($tools as $class) {
            $schemas[$class] = app($class)->toArray()['inputSchema'];
        }

        return $schemas;
    }

    public function test_every_variants_schema_lists_every_property_that_variant_rules_accepts()
    {
        $expected = $this->acceptedProperties();
        $this->assertContains('barcode_content', $expected);

        $checked = 0;

        foreach ($this->toolSchemas() as $class => $schema) {
            foreach ($this->variantSchemas($schema) as $variants) {
                $properties = array_keys($variants['items']['properties'] ?? []);
                sort($properties);

                $this->assertSame($expected, $properties, "{$class} lists other variant properties than VariantRules accepts.");

                foreach (array_diff($expected, ['layer']) as $property) {
                    $this->assertStringContainsString("\"{$property}\"", $variants['description'], "{$class} does not name {$property} in the variants description.");
                }

                $checked++;
            }
        }

        // create-snap, create-snaps-batch, preview-snap and preview-template.
        $this->assertGreaterThanOrEqual(4, $checked);
    }

    public function test_the_variant_properties_catalog_lists_every_accepted_property()
    {
        $catalog = array_column(VariantProperties::catalog(), 'property');
        sort($catalog);

        $this->assertSame(array_values(array_diff($this->acceptedProperties(), ['layer'])), $catalog);
    }

    public function test_the_server_instructions_name_every_accepted_property()
    {
        foreach (array_diff($this->acceptedProperties(), ['layer']) as $property) {
            $this->assertStringContainsString("\"{$property}\"", SnapsbrewServer::WORKFLOW);
        }
    }
}

<?php

namespace Tests\Unit\Support;

use App\Support\CreativePackageContract;
use App\Support\CreativePackageRequestValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreativePackageRequestValidatorTest extends TestCase
{
    private CreativePackageRequestValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new CreativePackageRequestValidator;
    }

    public function test_decoded_string_boundaries_are_exact(): void
    {
        foreach ([
            ['idempotency_key', 100], ['items.0.client_creative_id', 100],
            ['items.0.client_family_id', 100], ['items.0.label', 200],
            ['items.0.placement_key', 150], ['items.0.caption', 2200],
            ['items.0.alt_text', 1000],
        ] as [$path, $limit]) {
            $request = $this->fixture();
            data_set($request, $path, str_repeat('a', $limit));
            $this->validator->normalize($request);
            data_set($request, $path, str_repeat('a', $limit + 1));
            $this->assertInvalid($request, $path);
        }

        $request = $this->fixture();
        $request['items'][0]['alt_text'] = "\u{2003}".str_repeat('a', 1000)."\u{00A0}";
        $this->assertSame(str_repeat('a', 1000), $this->validator->normalize($request)['items'][0]['alt_text']);
    }

    public function test_item_and_metadata_limits_clamp_configuration(): void
    {
        $item = $this->fixture()['items'][0];
        foreach ([1, 10] as $count) {
            $this->assertCount($count, $this->validator->normalize($this->withItems($item, $count))['items']);
        }
        $this->assertInvalid($this->withItems($item, 0), 'items');
        config(['creative-packages.limits.max_items' => 11]);
        $this->assertInvalid($this->withItems($item, 11), 'items');
        config(['creative-packages.limits.max_items' => 2]);
        $this->assertInvalid($this->withItems($item, 3), 'items');

        config(['creative-packages.limits.max_items' => 10, 'creative-packages.limits.metadata_bytes' => CreativePackageContract::MAX_METADATA_BYTES + 1]);
        $request = $this->fixture();
        $request['items'][0]['metadata'] = ['v' => str_repeat('x', CreativePackageContract::MAX_METADATA_BYTES - 8)];
        $this->validator->normalize($request);
        $request['items'][0]['metadata']['v'] .= 'x';
        $this->assertInvalid($request, 'items.0.metadata');
        config(['creative-packages.limits.metadata_bytes' => 8]);
        $request['items'][0]['metadata'] = ['v' => 'x'];
        $this->assertInvalid($request, 'items.0.metadata');
    }

    public function test_metadata_depth_accepts_exact_and_rejects_one_over(): void
    {
        $request = $this->fixture();
        $request['items'][0]['metadata'] = $this->nested(6);
        $this->validator->normalize($request);
        $request['items'][0]['metadata'] = $this->nested(7);
        $this->assertInvalid($request, 'items.0.metadata');
        config(['creative-packages.limits.metadata_depth' => 2]);
        $request['items'][0]['metadata'] = $this->nested(3);
        $this->assertInvalid($request, 'items.0.metadata');
        config(['creative-packages.limits.metadata_depth' => 99]);
        $request['items'][0]['metadata'] = $this->nested(7);
        $this->assertInvalid($request, 'items.0.metadata');
    }

    public function test_required_fields_shapes_and_safe_keys_fail_closed(): void
    {
        foreach ([
            ['unexpected', true, 'unexpected'], ['items.0.publisher', true, 'items.0.publisher'],
            ['items', ['named' => $this->fixture()['items'][0]], 'items'],
            ['items.0.metadata', ['list'], 'items.0.metadata'], ['items.0.metadata', 'x', 'items.0.metadata'],
            ['idempotency_key', 'unsafe key', 'idempotency_key'],
            ['idempotency_key', CreativePackageContract::INTERNAL_KEY_PREFIX.'x', 'idempotency_key'],
            ['items.0.client_creative_id', 'unsafe key', 'items.0.client_creative_id'],
            ['items.0.template_id', 'bad', 'items.0.template_id'], ['items.0.format', 'gif', 'items.0.format'], ['items.0.label', '', 'items.0.label'],
        ] as [$path, $value, $error]) {
            $request = $this->fixture();
            data_set($request, $path, $value);
            $this->assertInvalid($request, $error);
        }

        foreach (['idempotency_key', 'items', 'items.0.client_creative_id', 'items.0.template_id', 'items.0.template_version', 'items.0.label', 'items.0.alt_text', 'items.0.format'] as $path) {
            foreach (['missing', null] as $value) {
                $request = $this->fixture();
                $value === 'missing' ? data_forget($request, $path) : data_set($request, $path, null);
                $this->assertInvalid($request, $path);
            }
        }
        $request = $this->fixture();
        $request['items'][1]['client_creative_id'] = $request['items'][0]['client_creative_id'];
        $this->assertInvalid($request, 'items.1.client_creative_id');
    }

    public function test_semantic_equivalents_have_one_canonical_form(): void
    {
        $first = $this->fixture();
        foreach (['client_family_id', 'placement_key', 'caption'] as $field) {
            $first['items'][0][$field] = null;
        }
        $first['items'][1]['metadata'] = null;
        $first['items'][0]['template_id'] = 'ABCDEF12-ABCD-4ABC-8ABC-ABCDEF123456';
        $first['items'][0]['template_version'] = '01';
        $first['items'][0]['metadata'] = ["Cafe\u{0301}" => ['empty' => [], 'text' => "Cafe\u{0301}", 'whole' => 1.0, 'zero' => -0.0, 'part' => 1.5]];
        $second = $this->fixture();
        $second['items'][0]['template_id'] = 'abcdef12-abcd-4abc-8abc-abcdef123456';
        unset($second['items'][0]['client_family_id'], $second['items'][0]['placement_key'], $second['items'][0]['caption'], $second['items'][1]['metadata']);
        $second['items'][0]['metadata'] = ['Café' => ['empty' => [], 'part' => 1.5, 'text' => 'Café', 'whole' => 1, 'zero' => 0]];
        $normalized = $this->validator->normalize($first);
        $this->assertSame($normalized, $this->validator->normalize($second));
        $this->assertSame([], $normalized['items'][0]['metadata']['Café']['empty']);
        $this->assertArrayNotHasKey('caption', $normalized['items'][0]);
        $second['items'] = array_reverse($second['items']);
        $this->assertNotSame($normalized, $this->validator->normalize($second));
    }

    public function test_versions_collisions_reserved_keys_and_blank_alt_text_are_rejected(): void
    {
        foreach ([0, -1, 1.5, 4294967296, '4294967296', null] as $version) {
            $request = $this->fixture();
            $request['items'][0]['template_version'] = $version;
            $this->assertInvalid($request, 'items.0.template_version');
        }
        $request = $this->fixture();
        $request['items'][0]['template_version'] = '4294967295';
        $this->assertSame(4294967295, $this->validator->normalize($request)['items'][0]['template_version']);
        foreach (array_merge(CreativePackageContract::RESERVED_METADATA_FIELDS, [CreativePackageContract::INTERNAL_KEY_PREFIX.'secret']) as $field) {
            $request = $this->fixture();
            $request['items'][0]['metadata'] = ['nested' => [strtoupper($field) => true]];
            $this->assertInvalid($request, 'items.0.metadata');
        }
        $request = $this->fixture();
        $request['items'][0]['metadata'] = ["Cafe\u{0301}" => null, 'Café' => 2];
        $this->assertInvalid($request, 'items.0.metadata');
        $request['items'][0]['metadata'] = [];
        $request['items'][0]['alt_text'] = "\u{2003}\u{00A0}";
        $this->assertInvalid($request, 'items.0.alt_text');
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/creative-package-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function withItems(array $item, int $count): array
    {
        $request = $this->fixture();
        $request['items'] = array_fill(0, $count, $item);
        foreach ($request['items'] as $index => &$entry) {
            $entry['client_creative_id'] = "creative-{$index}";
        }

        return $request;
    }

    private function nested(int $depth): array
    {
        $value = 'leaf';
        while ($depth-- > 0) {
            $value = ['level' => $value];
        }

        return $value;
    }

    private function assertInvalid(array $request, string $path): void
    {
        try {
            $this->validator->normalize($request);
            $this->fail("Expected {$path} to fail validation.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($path, $exception->errors());
        }
    }
}

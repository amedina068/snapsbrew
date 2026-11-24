<?php

namespace App\Editor;

use App\Exceptions\EditorOperationRejected;
use App\Rules\ValidCssColor;
use App\Support\EditorDocumentContract;

final class EditorDocumentOperationApplier
{
    private const TYPES = ['text', 'empty', 'qr_code', 'barcode', 'shape'];

    public function apply(array $document, array $operations): array
    {
        if (! array_is_list($operations) || $operations === []) {
            $this->reject('editor_operation_invalid_request');
        }

        foreach ($operations as $operation) {
            if (! is_array($operation) || ! is_string($operation['operation'] ?? null) || ! is_string($operation['layer_key'] ?? null)) {
                $this->reject('editor_operation_invalid_request');
            }
            $index = $this->layerIndex($document, $operation['layer_key']);
            if ($operation['operation'] === 'set_layer_property') {
                $this->setProperty($document['layers'][$index], $operation);
            } elseif ($operation['operation'] === 'set_layer_type') {
                $this->setType($document['layers'][$index], $operation);
            } else {
                $this->reject('editor_operation_unknown_type');
            }
        }

        return $document;
    }

    private function layerIndex(array $document, string $key): int
    {
        $layers = $document['layers'] ?? null;
        if (! is_array($layers) || ! array_is_list($layers)) {
            $this->reject('editor_operation_document_invalid');
        }
        $indices = array_keys(array_filter($layers, fn (mixed $layer): bool => is_array($layer) && ($layer['key'] ?? null) === $key));
        if (count($indices) !== 1) {
            $this->reject($indices === [] ? 'editor_operation_layer_not_found' : 'editor_operation_layer_not_unique');
        }

        return $indices[0];
    }

    private function setProperty(array &$layer, array $operation): void
    {
        $property = $operation['property'] ?? null;
        $constraints = EditorDocumentContract::catalog()['property_constraints'];

        if (! is_string($property) || ! array_key_exists('value', $operation) || ! isset($constraints[$property])) {
            $this->reject('editor_operation_invalid_property');
        }
        $value = $operation['value'];
        if (! $this->conforms($value, $constraints[$property])) {
            $this->reject($property === 'inlineSvg' ? 'editor_operation_invalid_svg' : 'editor_operation_invalid_value');
        }

        if ($property === 'inlineSvg' && ($value = SvgSanitizer::sanitize($value)) === null) {
            $this->reject('editor_operation_invalid_svg');
        }
        if (! isset($layer['properties']) || ! is_array($layer['properties']) || ! array_is_list($layer['properties'])) {
            $this->reject('editor_operation_document_invalid');
        }
        foreach ($layer['properties'] as &$existing) {
            if (is_array($existing) && ($existing['name'] ?? null) === $property) {
                $existing['value'] = $value;

                return;
            }
        }
        unset($existing);

        $layer['properties'][] = ['name' => $property, 'value' => $value];
    }

    private function setType(array &$layer, array $operation): void
    {
        $type = $operation['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            $this->reject('editor_operation_invalid_layer_type');
        }

        $property = collect($layer['properties'] ?? [])->firstWhere('name', 'inlineSvg');
        $svg = is_array($property) ? ($property['value'] ?? null) : null;
        if ($type === 'shape' && SvgSanitizer::sanitize($svg) === null) {
            $this->reject('editor_operation_shape_requires_svg');
        }
        $layer['type'] = $type;
    }

    private function conforms(mixed $value, array $constraint): bool
    {
        $type = $constraint['type'] ?? null;
        $valid = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && ! array_is_list($value),
            default => false,
        };
        $invalid = ! $valid
            || (isset($constraint['enum']) && ! in_array($value, $constraint['enum'], true))
            || (isset($constraint['minimum']) && $value < $constraint['minimum'])
            || (isset($constraint['maximum']) && $value > $constraint['maximum'])
            || (isset($constraint['maximum_bytes']) && strlen($value) > $constraint['maximum_bytes'])
            || (($constraint['format'] ?? null) === 'css_color' && ! ValidCssColor::matches($value));
        if ($invalid) {
            return false;
        }
        if (! isset($constraint['properties'])) {
            return true;
        }
        if (array_diff($constraint['required'] ?? [], array_keys($value)) !== []) {
            return false;
        }
        foreach ($value as $name => $nested) {
            if (! is_string($name) || ! isset($constraint['properties'][$name]) || ! $this->conforms($nested, $constraint['properties'][$name])) {
                return false;
            }
        }

        return true;
    }

    private function reject(string $code): never
    {
        throw new EditorOperationRejected($code);
    }
}

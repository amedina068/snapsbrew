<?php

namespace App\Parsers;

class TemplateLayerParser
{
    public function __construct(
        public array $layers
    ) {}

    public function parse()
    {
        return collect($this->layers)
            ->map(fn ($layer) => $this->parseLayer($layer))
            ->toArray();
    }

    public function parseLayer($layer)
    {
        $type = $layer['type'] ?? null;
        $properties = collect($layer['properties'] ?? []);
        $availableModifications = [];

        if ($type == 'image') {
            $availableModifications = $properties
                ->filter(fn ($property) => ($property['name'] ?? null) == 'backgroundImage')
                ->mapWithKeys(fn ($property) => ['image_url' => $property['value'] ?? null]);
        }

        if ($type == 'text') {
            $availableModifications = $properties
                ->filter(fn ($property) => ($property['name'] ?? null) == 'textContent')
                ->mapWithKeys(fn ($property) => ['text' => $property['value'] ?? null]);
        }

        if ($type == 'qr_code') {
            $availableModifications = $properties
                ->filter(fn ($property) => ($property['name'] ?? null) == 'qrContent')
                ->mapWithKeys(fn ($property) => ['qr_content' => $property['value'] ?? null]);
        }

        if ($type == 'barcode') {
            $availableModifications = $properties
                ->filter(fn ($property) => ($property['name'] ?? null) == 'barcodeContent')
                ->mapWithKeys(fn ($property) => ['barcode_content' => $property['value'] ?? null]);
        }

        return [
            'layer' => $layer['key'] ?? null,
            'available_modifications' => $availableModifications,
        ];
    }
}

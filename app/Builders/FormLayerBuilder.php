<?php

namespace App\Builders;

class FormLayerBuilder
{
    const LAYER_TYPE_TEXT = 'text';

    const LAYER_TYPE_IMAGE = 'image';

    const LAYER_TYPE_EMPTY = 'empty';

    const LAYER_TYPE_QR_CODE = 'qr_code';

    const LAYER_TYPE_BARCODE = 'barcode';

    public function __construct(
        private array $layer
    ) {}

    public function parse()
    {
        return [
            'key' => $this->layer['key'],
            'type' => $this->layer['type'],
            'default_value' => $this->getLayerDefaultValue(),
            'content_type' => $this->getLayerContentType(),
        ];
    }

    private function getLayerDefaultValue()
    {
        if ($this->layer['type'] == self::LAYER_TYPE_TEXT) {
            return $this->textLayerDefaultValue();
        }

        if ($this->layer['type'] == self::LAYER_TYPE_IMAGE) {
            return $this->imageLayerDefaultValue();
        }

        if ($this->layer['type'] == self::LAYER_TYPE_EMPTY) {
            return $this->emptyLayerDefaultValue();
        }

        if ($this->layer['type'] == self::LAYER_TYPE_QR_CODE) {
            return $this->qrCodeLayerDefaultValue();
        }

        if ($this->layer['type'] == self::LAYER_TYPE_BARCODE) {
            return $this->barcodeLayerDefaultValue();
        }
    }

    private function imageLayerDefaultValue()
    {
        $property = collect($this->layer['properties'])->first(function ($property) {
            return $property['name'] == 'backgroundImage';
        });

        return $property['value'];
    }

    private function textLayerDefaultValue()
    {
        $property = collect($this->layer['properties'])->first(function ($property) {
            return $property['name'] == 'textContent';
        });

        return $property['value'];
    }

    private function emptyLayerDefaultValue()
    {
        $property = collect($this->layer['properties'])->first(function ($property) {
            return $property['name'] == 'backgroundColor';
        });

        return $property['value'];
    }

    private function qrCodeLayerDefaultValue()
    {
        $property = collect($this->layer['properties'])->first(function ($property) {
            return $property['name'] == 'qrContent';
        });

        return $property['value'];
    }

    private function barcodeLayerDefaultValue()
    {
        $property = collect($this->layer['properties'])->first(function ($property) {
            return $property['name'] == 'barcodeContent';
        });

        return $property['value'] ?? null;
    }

    private function getLayerContentType()
    {
        if ($this->layer['type'] !== self::LAYER_TYPE_QR_CODE) {
            return null;
        }

        $property = collect($this->layer['properties'])->first(function ($property) {
            return $property['name'] == 'qrContentType';
        });

        return $property['value'] ?? null;
    }
}

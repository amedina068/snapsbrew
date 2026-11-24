<?php

namespace App\Builders\PropertyBuilders;

class BackgroundPropertyBuilder
{
    public $backgroundType;

    public $builder;

    public function __construct($backgroundType)
    {
        $this->builder = $this->getBackgroundBuilder($backgroundType);
    }

    private function getBackgroundBuilder($backgroundType)
    {
        return match ($backgroundType) {
            'radial' => (new RadialBackgroundPropertyBuilder),
            default => (new LinearBackgroundPropertyBuilder),
        };
    }

    public function generateHtmlStyle($propertyValue)
    {
        return $this->builder->generateHtmlStyle($propertyValue);
    }
}

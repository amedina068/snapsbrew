<?php

namespace App\Builders\PropertyBuilders;

use App\Support\CssSanitizer;

class LinearBackgroundPropertyBuilder
{
    public function generateHtmlStyle($propertyValue)
    {
        $colors = $this->getColors($propertyValue['steps'] ?? []);
        $degrees = $this->getDegrees($propertyValue['degrees'] ?? 0);

        return "background: linear-gradient({$degrees}, {$colors});";
    }

    private function getColors($steps)
    {
        return collect($steps)
            ->map(function ($step) {
                // Color is user-derived and lands inside linear-gradient(...);
                // escape it so it can't close the declaration. Percentage is
                // numeric-guarded.
                $string = CssSanitizer::value($step['color'] ?? '');
                if (is_numeric($step['percentage'] ?? null)) {
                    $string .= ' '.$step['percentage'].'%';
                }

                return $string;
            })
            ->join(',');
    }

    private function getDegrees($degrees)
    {
        return ((float) $degrees).'deg';
    }
}

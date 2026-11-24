<?php

namespace App\Builders\PropertyBuilders;

use App\Support\CssSanitizer;

class RadialBackgroundPropertyBuilder
{
    public function generateHtmlStyle($propertyValue)
    {
        $from = CssSanitizer::value($propertyValue['from'] ?? '');
        $to = CssSanitizer::value($propertyValue['to'] ?? '');

        return "background: radial-gradient({$from}, {$to});";
    }
}

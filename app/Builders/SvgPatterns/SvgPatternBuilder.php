<?php

namespace App\Builders\SvgPatterns;

class SvgPatternBuilder
{
    const FALLBACK_DESIGN = 'diagonalStripes';

    private $color = '#64748b';

    public function __construct(
        private $design
    ) {}

    public function color($color)
    {
        $this->color = $color;

        return $this;
    }

    public function html()
    {
        $patterns = SvgPatternData::PATTERNS;
        $template = $patterns[$this->design] ?? $patterns[self::FALLBACK_DESIGN];

        return str_replace('{svgColor}', rawurlencode($this->color), $template);
    }
}

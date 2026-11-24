<?php

namespace App\Builders;

use App\Models\Font;

class SnapBuilder
{
    private $variables;

    private $width;

    private $height;

    private $layers;

    private $groups;

    private $companyId;

    public function setHeight($height)
    {
        $this->height = $height;

        return $this;
    }

    public function setWidth($width)
    {
        $this->width = $width;

        return $this;
    }

    public function setLayers($layers)
    {
        $this->layers = $layers;

        return $this;
    }

    public function withVariables($variables)
    {
        $this->variables = $variables;

        return $this;
    }

    public function setGroups($groups)
    {
        $this->groups = $groups;

        return $this;
    }

    public function forCompany($companyId)
    {
        $this->companyId = $companyId;

        return $this;
    }

    /**
     * The set of group ids whose `visible` flag is false. Decoded ONCE here so
     * the per-layer render loop is a cheap array lookup, not a repeated decode.
     *
     * @return array<string, true>
     */
    private function hiddenGroupIds(): array
    {
        $groups = is_string($this->groups) ? json_decode($this->groups, true) : $this->groups;

        if (! is_array($groups)) {
            return [];
        }

        $hidden = [];
        foreach ($groups as $group) {
            if (is_array($group) && array_key_exists('id', $group) && ($group['visible'] ?? true) === false) {
                $hidden[$group['id']] = true;
            }
        }

        return $hidden;
    }

    public function buildContent()
    {
        return '<div style="'.$this->buildHtmlStyles().'">'.$this->buildHtmlContent().'</div>';
    }

    public function buildHead()
    {
        return $this->buildFontsLink().$this->buildFontFaces().$this->buildAutoResizeScript();
    }

    /**
     * Emit an @font-face for each company custom font whose family is actually
     * used by a layer (F5) — in addition to the Google Fonts link. Scoped to the
     * builder's company; family is allowlisted (it lands in CSS); the src is an
     * app-issued media URL, not user input.
     */
    private function buildFontFaces()
    {
        if (! $this->companyId) {
            return '';
        }

        $families = collect(json_decode($this->layers, true))
            ->map(fn ($layer) => collect($layer['properties'] ?? [])->firstWhere('name', 'fontFamily')['value'] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($families->isEmpty()) {
            return '';
        }

        return Font::where('company_id', $this->companyId)
            ->whereIn('family', $families)
            ->get()
            ->map(function ($font) {
                $family = preg_match(Font::FAMILY_PATTERN, (string) $font->family) ? $font->family : null;
                $url = $font->url;

                if (! $family || ! $url) {
                    return '';
                }

                return "<style>@font-face{font-family:'".$family."';src:url('".$url."');}</style>";
            })
            ->filter()
            ->implode('');
    }

    /**
     * A static, synchronous shrink-to-fit script (F4). It binary-searches the
     * largest font-size at which each [data-autoresize] layer's content still
     * fits, running on DOMContentLoaded — before Browsershot's
     * waitUntilNetworkIdle screenshots. No rAF/setTimeout, and no
     * PHP-interpolated user value: the script body is a constant and reads all
     * dimensions from the DOM. v1 is shrink-only (max starts at the computed
     * size).
     */
    private function buildAutoResizeScript()
    {
        return <<<'HTML'
<script>
(function () {
    function fit(el) {
        var max = parseFloat(getComputedStyle(el).fontSize) || 100;
        var min = 1, best = min;
        for (var i = 0; i < 20; i++) {
            var mid = (min + max) / 2;
            el.style.fontSize = mid + 'px';
            if (el.scrollHeight <= el.clientHeight && el.scrollWidth <= el.clientWidth) {
                best = mid; min = mid;
            } else {
                max = mid;
            }
        }
        el.style.fontSize = best + 'px';
    }
    function run() {
        var nodes = document.querySelectorAll('[data-autoresize]');
        for (var i = 0; i < nodes.length; i++) { fit(nodes[i]); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
</script>
HTML;
    }

    private function buildFontsLink()
    {
        return '<link href="https://fonts.googleapis.com/css2?family='.$this->buildFonts().'" rel="stylesheet">';
    }

    private function buildFonts()
    {
        return collect(json_decode($this->layers, true))->map(function ($layer) {
            $fontFamily = collect($layer['properties'] ?? [])->firstWhere('name', 'fontFamily');

            return $fontFamily ? $this->encodeFontFamily($fontFamily['value']) : '';
        })->filter()->values()->implode('&family=');
    }

    /**
     * S4: a fontFamily value is concatenated into the Google Fonts <link href>.
     * An unconstrained value (`Roboto"><script>` or `Roboto;}body{…`) breaks out
     * of the attribute / injects CSS in the headless Chrome page. Allowlist the
     * family to letters, digits, spaces and `_-`; drop anything else; then
     * url-encode (spaces become `+` for the Google Fonts API).
     */
    private function encodeFontFamily($value): string
    {
        $value = (string) $value;

        if (! preg_match('/^[A-Za-z0-9 _-]{1,100}$/', $value)) {
            return '';
        }

        return str_replace('%20', '+', rawurlencode($value));
    }

    private function buildHtmlStyles()
    {
        return $this->defaultStyles().'width:'.$this->width.'px;height:'.$this->height.'px;';
    }

    private function defaultStyles()
    {
        return 'position:relative;overflow:hidden;';
    }

    private function buildHtmlContent()
    {
        $variables = collect($this->variables)->groupBy('layer');
        $hiddenGroups = $this->hiddenGroupIds();

        return collect(json_decode($this->layers, true))
            ->reduce(function ($carry, $layer) use ($variables, $hiddenGroups) {
                $overwrite = $variables->get($layer['key'] ?? null, collect());

                // A layer assigned to a hidden group drops from the render — same
                // mechanism as the `hidden` variant. Absent/null/dangling groupId
                // renders (ungrouped, back-compat).
                $groupId = $layer['groupId'] ?? null;
                if ($groupId !== null && isset($hiddenGroups[$groupId])) {
                    return $carry;
                }

                // A variant carrying `hidden: true` drops the layer from the
                // render entirely — used by onboarding to show only the
                // categories the user picked.
                if (collect($overwrite)->contains(fn ($variant) => ! empty($variant['hidden']))) {
                    return $carry;
                }

                return $carry .= (new LayerBuilder($layer))->overwrite($overwrite)->build();
            }, '');
    }
}

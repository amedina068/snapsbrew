<?php

namespace App\Builders;

use App\Builders\PropertyBuilders\BackgroundPropertyBuilder;
use App\Builders\SvgPatterns\SvgPatternBuilder;
use App\Editor\ClippedImage;
use App\Editor\SvgSanitizer;
use App\Support\CssSanitizer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Picqer\Barcode\BarcodeGeneratorSVG;
use Throwable;

class LayerBuilder
{
    private $overwrite = null;

    const CONTENT_PROPERTIES = [
        'textContent',
    ];

    /**
     * The only barcode symbologies we render in v1. The user-facing format name
     * maps to a fixed picqer type constant — the raw value is NEVER passed to
     * the generator, so an unknown format renders the placeholder, not arbitrary
     * input. Keep in sync with App\Rules\ValidLayers (the boundary enum).
     */
    const BARCODE_FORMATS = [
        'Code128' => BarcodeGeneratorSVG::TYPE_CODE_128,
        'EAN-13' => BarcodeGeneratorSVG::TYPE_EAN_13,
        'UPC-A' => BarcodeGeneratorSVG::TYPE_UPC_A,
    ];

    const STYLE_PROPERTIES = [
        'backgroundGradientColor' => 'background',
        'backgroundColor' => 'background',
        'backgroundSvg' => 'background',
        'backgroundImage' => 'background',
        'zIndex' => 'z-index',
        'shadow' => 'box-shadow',
        'height' => 'height',
        'width' => 'width',
        'positionX' => 'left',
        'textAlignment' => 'text-align',
        'fontColor' => 'color',
        'positionY' => 'top',
        'fontFamily' => 'font-family',
        'fontWeight' => 'font-weight',
        'fontStyle' => 'font-style',
        'letterSpacing' => 'letter-spacing',
        'borderRadiusLeftTop' => 'border-top-left-radius',
        'borderRadiusRightTop' => 'border-top-right-radius',
        'borderRadiusRightBottom' => 'border-bottom-right-radius',
        'borderRadiusLeftBottom' => 'border-bottom-left-radius',
        'lineHeight' => 'line-height',
        'fontSize' => 'font-size',
        'borderStyle' => 'border-style',
        'borderWidth' => 'border-width',
        'borderColor' => 'border-color',
        'opacity' => 'opacity',
        'mixBlendMode' => 'mix-blend-mode',
        'effects' => 'effects',
        'transformOrigin' => 'transform-origin',
        'clipContent' => 'overflow',
    ];

    /**
     * Transform contributors. `rotate` used to map straight to `transform`, but
     * flip adds two more contributors — they MUST compose into a single
     * `transform:` declaration or the last one silently wins and drops the rest.
     */
    const TRANSFORM_PROPERTIES = ['rotate', 'flipHorizontal', 'flipVertical'];

    /**
     * CSS filter functions and their identity (no-op) value. A filter is emitted
     * only when at least one value differs from identity, so unset filters never
     * override the browser default. Keep in sync with LayerSchema + the editor.
     */
    const FILTER_PROPERTIES = [
        'filterBlur' => ['fn' => 'blur', 'unit' => 'px', 'identity' => 0],
        'filterBrightness' => ['fn' => 'brightness', 'unit' => '', 'identity' => 1],
        'filterContrast' => ['fn' => 'contrast', 'unit' => '', 'identity' => 1],
        'filterSaturate' => ['fn' => 'saturate', 'unit' => '', 'identity' => 1],
        'filterGrayscale' => ['fn' => 'grayscale', 'unit' => '', 'identity' => 0],
    ];

    public function __construct(
        private $layer
    ) {}

    public function overwrite($overwrite)
    {
        $this->overwrite = $overwrite;

        return $this;
    }

    public function build()
    {
        $autoResize = $this->autoResizeEnabled() ? ' data-autoresize="true"' : '';

        return '<div'.$autoResize.' style="'.$this->buildStyles().'">'.$this->buildContent().'</div>';
    }

    private function autoResizeEnabled(): bool
    {
        return $this->isTruthy($this->propertyValue(collect($this->layer['properties'] ?? []), 'autoResize'));
    }

    private function buildStyles()
    {
        $properties = collect($this->layer['properties'] ?? []);

        $autoResize = $this->autoResizeEnabled();

        $base = $properties
            ->filter(fn ($property) => array_key_exists($property['name'] ?? '', self::STYLE_PROPERTIES))
            // When auto-resize is on the head script owns font-size; a fixed
            // value would be the wrong binary-search starting point.
            ->reject(fn ($property) => $autoResize && $property['name'] === 'fontSize')
            ->reduce(fn ($carry, $property) => $carry .= $this->buildProperty($property), '');

        $overflow = $autoResize ? 'overflow:hidden;' : '';

        return $this->defaultStyles().$base.$overflow.$this->buildTransform($properties).$this->buildFilter($properties);
    }

    /**
     * Compose all transform contributors (rotate + flips) into ONE declaration.
     */
    private function buildTransform($properties)
    {
        $parts = [];

        $rotate = $this->propertyValue($properties, 'rotate');
        if ($rotate !== null && (float) $rotate != 0.0) {
            $parts[] = 'rotate('.(float) $rotate.'deg)';
        }

        if ($this->isTruthy($this->propertyValue($properties, 'flipHorizontal'))) {
            $parts[] = 'scaleX(-1)';
        }

        if ($this->isTruthy($this->propertyValue($properties, 'flipVertical'))) {
            $parts[] = 'scaleY(-1)';
        }

        $origin = $this->propertyValue($properties, 'transformOrigin');
        $origin = in_array($origin, ['center', 'top left', 'top right', 'bottom left', 'bottom right'], true) ? $origin : 'center';

        return $parts ? 'transform-origin:'.$origin.';transform: '.implode(' ', $parts).';' : '';
    }

    /**
     * Compose all non-identity filters into ONE declaration; emit nothing when
     * every filter is at its identity value so the browser default stands.
     */
    private function buildFilter($properties)
    {
        $parts = [];

        foreach (self::FILTER_PROPERTIES as $name => $spec) {
            $value = $this->propertyValue($properties, $name);

            if ($value === null || ! is_numeric($value)) {
                continue;
            }

            if ((float) $value == (float) $spec['identity']) {
                continue;
            }

            $parts[] = $spec['fn'].'('.(float) $value.$spec['unit'].')';
        }

        return $parts ? 'filter: '.implode(' ', $parts).';' : '';
    }

    private function propertyValue($properties, string $name)
    {
        $property = collect($properties)->firstWhere('name', $name);

        return $property['value'] ?? null;
    }

    private function isTruthy($value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    private function defaultStyles()
    {
        return 'box-sizing:border-box;position: absolute;background-repeat:no-repeat;background-position:center;';
    }

    private function buildProperty($property)
    {
        if ($property['name'] == 'backgroundImage') {
            $imageUrl = $property['value'];
            if ($this->overwrite) {
                $modification = collect($this->overwrite)->last(function ($modification) {
                    return array_key_exists('image_url', $modification);
                });

                if ($modification) {
                    $imageUrl = $modification['image_url'];
                }
            }

            $backgroundSizeProp = collect($this->layer['properties'])->firstWhere('name', 'backgroundSize');
            $backgroundSizeValue = $backgroundSizeProp ? $backgroundSizeProp['value'] : 'cover';
            $crop = [
                'fit' => collect($this->layer['properties'])->firstWhere('name', 'imageFit')['value'] ?? $backgroundSizeValue,
                'x' => collect($this->layer['properties'])->firstWhere('name', 'imagePositionX')['value'] ?? 50,
                'y' => collect($this->layer['properties'])->firstWhere('name', 'imagePositionY')['value'] ?? 50,
                'focal_x' => collect($this->layer['properties'])->firstWhere('name', 'imageFocalX')['value'] ?? null,
                'focal_y' => collect($this->layer['properties'])->firstWhere('name', 'imageFocalY')['value'] ?? null,
                'mask' => collect($this->layer['properties'])->firstWhere('name', 'imageMask')['value'] ?? 'none',
            ];

            $imageUrl = $this->escapeCssValue($imageUrl);
            $backgroundSizeValue = $this->escapeCssValue($backgroundSizeValue);

            return "background-image: url('{$imageUrl}');".ClippedImage::css($crop);
        }

        if (in_array($property['name'], ['backgroundColor', 'backgroundGradientColor', 'backgroundSvg'])) {
            return $this->getBackgroundStyle($property);
        }

        if (in_array($property['name'], [
            'height',
            'width',
            'positionX',
            'positionY',
            'borderRadiusLeftTop',
            'borderWidth',
            'borderRadiusRightTop',
            'borderRadiusRightBottom',
            'borderRadiusLeftBottom',
        ])) {
            return self::STYLE_PROPERTIES[$property['name']].':'.(float) $property['value'].'px;';
        }

        if (in_array($property['name'], ['shadow'])) {
            $shadow = $property['value'];

            return self::STYLE_PROPERTIES[$property['name']].':'
                .$this->escapeCssValue($shadow['x']).'px '
                .$this->escapeCssValue($shadow['y']).'px '
                .$this->escapeCssValue($shadow['blur']).'px '
                .$this->escapeCssValue($shadow['spread']).'px '
                .$this->escapeCssValue($shadow['color']).';';
        }

        if ($property['name'] === 'effects' && is_array($property['value'])) {
            $effects = $property['value'];
            $blur = is_numeric($effects['blur'] ?? null) ? max(0, min(100, (float) $effects['blur'])) : 0;
            $stroke = is_array($effects['stroke'] ?? null) ? $effects['stroke'] : [];
            $strokeWidth = is_numeric($stroke['width'] ?? null) ? max(0, min(100, (float) $stroke['width'])) : 0;
            $strokeColor = $this->escapeCssValue($stroke['color'] ?? 'transparent');
            $strokeStyle = in_array($stroke['style'] ?? null, ['solid', 'dashed', 'dotted'], true) ? $stroke['style'] : 'solid';

            return ($blur ? "filter:blur({$blur}px);" : '')
                .($strokeWidth ? "border:{$strokeWidth}px {$strokeStyle} {$strokeColor};" : '');
        }

        if (in_array($property['name'], ['fontSize', 'lineHeight'])) {
            return self::STYLE_PROPERTIES[$property['name']].':'.(float) $property['value'].'px;';
        }

        if ($property['name'] === 'letterSpacing') {
            return 'letter-spacing:'.max(-20, min(100, (float) $property['value'])).'px;';
        }

        if ($property['name'] === 'fontWeight') {
            $weight = is_numeric($property['value']) ? (int) $property['value'] : 400;

            return 'font-weight:'.max(100, min(900, $weight)).';';
        }

        if ($property['name'] === 'fontStyle') {
            return 'font-style:'.(in_array($property['value'], ['normal', 'italic'], true) ? $property['value'] : 'normal').';';
        }

        if ($property['name'] === 'clipContent') {
            return 'overflow:'.($this->isTruthy($property['value']) ? 'hidden' : 'visible').';';
        }

        if ($property['name'] === 'transformOrigin') {
            return '';
        }

        if (in_array($property['name'], ['fontFamily'])) {
            return self::STYLE_PROPERTIES[$property['name']].':\''.$this->escapeCssValue($property['value']).'\';';
        }

        return self::STYLE_PROPERTIES[$property['name']].':'.$this->escapeCssValue($property['value']).';';
    }

    private function getBackgroundStyle($property)
    {
        $propertyName = $property['name'];
        $propertyValue = $property['value'] ?? null;

        if ($propertyName == 'backgroundColor') {
            if ($this->overwrite) {
                $modification = collect($this->overwrite)->last(function ($modification) {
                    return array_key_exists('background_color', $modification);
                });

                if ($modification) {
                    $propertyValue = $modification['background_color'];
                }
            }
        }

        $backgroundType = collect($this->layer['properties'] ?? [])->firstWhere('name', 'backgroundType')['value'] ?? null;

        return match ($propertyName) {
            'backgroundColor' => 'background: '.$this->escapeCssValue($propertyValue).';',
            'backgroundGradientColor' => (new BackgroundPropertyBuilder($backgroundType))->generateHtmlStyle($propertyValue),
            'backgroundSvg' => 'background: url(\''.(new SvgPatternBuilder($propertyValue['design'] ?? null))
                ->color($propertyValue['color'] ?? null)
                ->html().'\');',
        };
    }

    private function buildContent()
    {
        if (($this->layer['type'] ?? null) === 'shape') {
            $svg = SvgSanitizer::sanitize($this->propertyValue(collect($this->layer['properties'] ?? []), 'inlineSvg'));

            return $svg ? $svg : '';
        }
        if (($this->layer['type'] ?? null) === 'qr_code') {
            return $this->buildQrImage() ?? $this->buildQrPlaceholder();
        }

        if (($this->layer['type'] ?? null) === 'barcode') {
            return $this->buildBarcodeImage() ?? $this->buildBarcodePlaceholder();
        }

        return collect($this->layer['properties'] ?? [])
            ->filter(fn ($property) => in_array($property['name'] ?? '', self::CONTENT_PROPERTIES))
            ->reduce(function ($carry, $property) {
                $modification = collect($this->overwrite)->last(function ($modification) {
                    return array_key_exists('text', $modification);
                });

                if ($modification) {
                    return $carry .= $this->escapeHtml($modification['text']);
                }

                return $carry .= $this->escapeHtml($property['value'] ?? '');
            }, '');
    }

    /**
     * Escape a user-derived value for HTML text context. The render path emits
     * into headless Chrome, so unescaped text is stored XSS. Plain text only —
     * no rich-HTML product requirement.
     */
    private function escapeHtml($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function escapeCssValue($value): string
    {
        return CssSanitizer::value($value);
    }

    private function buildQrPlaceholder(): string
    {
        $content = $this->resolveQrContent();
        $contentType = strtoupper($this->getPropertyValue('qrContentType', 'text'));

        return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:100%;font-weight:600;font-size:12px;color:#0f172a;text-transform:uppercase;background:rgba(148,163,184,0.15);border:1px dashed rgba(15,23,42,0.35);border-radius:4px;padding:8px;text-align:center;">'
            .'QR CODE ('.$this->escapeHtml($contentType).')<br><span style="font-size:11px;text-transform:none;word-break:break-word;">'.$this->escapeHtml($content ?? 'No content').'</span></div>';
    }

    private function buildQrImage(): ?string
    {
        $content = $this->resolveQrContent();

        if (! $content) {
            return null;
        }

        try {
            $renderer = new ImageRenderer(
                new RendererStyle($this->getQrSize(), 0),
                new SvgImageBackEnd
            );

            $writer = new Writer($renderer);
            $svgString = $writer->writeString($content);

            $dataUri = sprintf(
                'data:image/svg+xml;base64,%s',
                base64_encode($svgString)
            );

            return '<img src="'.$dataUri.'" alt="QR code" style="width:100%;height:100%;object-fit:contain;" />';
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function getQrSize(): int
    {
        $width = (int) $this->getPropertyValue('width', 200);
        $height = (int) $this->getPropertyValue('height', 200);

        return max(100, min($width, $height));
    }

    private function resolveQrContent()
    {
        $content = $this->getPropertyValue('qrContent');

        if (! $this->overwrite) {
            return $content;
        }

        $modification = collect($this->overwrite)->last(function ($modification) {
            return array_key_exists('qr_content', $modification);
        });

        if ($modification && isset($modification['qr_content'])) {
            return $modification['qr_content'];
        }

        return $content;
    }

    private function buildBarcodeImage(): ?string
    {
        $content = $this->resolveBarcodeContent();

        if (! $content) {
            return null;
        }

        $format = $this->getPropertyValue('barcodeFormat', 'Code128');
        $type = self::BARCODE_FORMATS[$format] ?? null;

        if (! $type) {
            return null;
        }

        try {
            $svg = (new BarcodeGeneratorSVG)->getBarcode((string) $content, $type);

            $dataUri = sprintf('data:image/svg+xml;base64,%s', base64_encode($svg));

            return '<img src="'.$dataUri.'" alt="Barcode" style="width:100%;height:100%;object-fit:contain;" />';
        } catch (Throwable $exception) {
            // Invalid content for the chosen symbology (e.g. EAN-13 with letters)
            // throws — render the placeholder rather than 500 the whole snap.
            report($exception);

            return null;
        }
    }

    private function buildBarcodePlaceholder(): string
    {
        $content = $this->resolveBarcodeContent();
        $format = strtoupper($this->getPropertyValue('barcodeFormat', 'code128'));

        return '<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;width:100%;height:100%;font-weight:600;font-size:12px;color:#0f172a;text-transform:uppercase;background:rgba(148,163,184,0.15);border:1px dashed rgba(15,23,42,0.35);border-radius:4px;padding:8px;text-align:center;">'
            .'BARCODE ('.$this->escapeHtml($format).')<br><span style="font-size:11px;text-transform:none;word-break:break-word;">'.$this->escapeHtml($content ?? 'No content').'</span></div>';
    }

    private function resolveBarcodeContent()
    {
        $content = $this->getPropertyValue('barcodeContent');

        if (! $this->overwrite) {
            return $content;
        }

        $modification = collect($this->overwrite)->last(function ($modification) {
            return array_key_exists('barcode_content', $modification);
        });

        if ($modification && isset($modification['barcode_content'])) {
            return $modification['barcode_content'];
        }

        return $content;
    }

    private function getPropertyValue(string $name, $default = null)
    {
        $property = collect($this->layer['properties'] ?? [])->firstWhere('name', $name);

        return $property['value'] ?? $default;
    }
}

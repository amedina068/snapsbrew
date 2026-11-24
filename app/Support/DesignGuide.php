<?php

namespace App\Support;

/**
 * Single source of truth for template DESIGN GUIDANCE — the opinionated rules,
 * recipes, and defaults an MCP agent should follow to build good-looking snaps
 * with the layer vocabulary Snapsbrew actually renders. Exposed via the
 * design-guide tool.
 *
 * This is guidance, not schema. It describes HOW to compose layers well; the
 * WHAT (property names, value shapes) lives in App\Support\LayerSchema
 * (design-time) and App\Support\VariantProperties (run-time overrides). Every
 * rule here must only reference properties those two classes expose — do not
 * invent vocabulary.
 */
class DesignGuide
{
    /**
     * Full guidance reference for the agent.
     *
     * @return array<string, mixed>
     */
    public static function catalog(): array
    {
        return [
            'how_to_use' => 'Read this BEFORE calling create-template. It gives canvas '
                .'presets, a layer-order convention, and composition recipes. Combine with '
                .'describe-layers (the property vocabulary + svg_pattern_designs list) — this '
                .'guide tells you WHICH properties to reach for and WHY; describe-layers tells '
                .'you their exact names and value shapes. Always preview-template before the '
                .'final render.',

            'first_principles' => [
                'One idea per snap. A promo has ONE headline and ONE call to action, not five competing messages.',
                'Contrast is the whole job: dark text on light background or light text on dark. If you cannot read it at thumbnail size, it fails.',
                'Hierarchy by size, not by decoration. Biggest thing = most important thing. Two or three type sizes max.',
                'Respect the safe margin. Keep every text/logo/CTA inside a padding inset from the canvas edge (see canvas_presets.safe_margin_px). Edges get cropped by social platforms.',
                'Align to an invisible grid. Share one left edge (positionX) across stacked text; do not scatter positions.',
                'Restraint reads as premium. Fewer layers, fewer fonts, fewer colors. A busy canvas reads as spam.',
            ],

            'canvas_presets' => [
                'note' => 'The canvas size is set on create-template, not on a layer. Pass the preset '
                    .'`size` (for example "instagram_story") and the dimensions are set for you; pass '
                    .'`width`/`height` only for a size no preset covers. safe_margin_px is the inset to '
                    .'keep important content away from the edges. The full list is below and also comes '
                    .'from the list-template-sizes tool.',
                'presets' => TemplateSizes::catalog(),
            ],

            'layer_order' => [
                'note' => 'Layers paint back-to-front by zIndex (low = behind). Build the stack in '
                    .'this order and give each tier a zIndex range so nothing hides the text.',
                'tiers' => [
                    ['tier' => 'base fill', 'zIndex' => '0', 'what' => 'One full-canvas layer: a backgroundColor or a backgroundGradientColor (backgroundType "linear"/"radial").'],
                    ['tier' => 'texture', 'zIndex' => '1', 'what' => 'Optional full-canvas backgroundSvg pattern at low opacity (0.05–0.15) for depth. Keep its color close to the base fill.'],
                    ['tier' => 'imagery', 'zIndex' => '2', 'what' => 'Photos / product shots (backgroundImage layers). Darken with an overlay if text sits on top.'],
                    ['tier' => 'overlay scrim', 'zIndex' => '3', 'what' => 'Optional semi-transparent dark/light box over a photo so text stays legible (backgroundColor + opacity ~0.4).'],
                    ['tier' => 'content', 'zIndex' => '4', 'what' => 'Headline, subhead, body text. The reason the snap exists.'],
                    ['tier' => 'marks', 'zIndex' => '5', 'what' => 'Logo, CTA pill, QR code, barcode — the actionable / brand elements, on top of everything.'],
                ],
            ],

            'typography' => [
                'scale' => 'On a 1080px canvas: headline 64–120px, subhead 36–56px, body 24–36px, '
                    .'fine print 18–22px. Scale proportionally for other sizes.',
                'pairing' => 'One display font for the headline + one clean font for body is plenty. '
                    .'fontFamily is any Google Fonts name (loaded automatically). Safe pairings: '
                    .'"Poppins"/"Inter", "Oswald"/"Roboto", "Playfair Display"/"Lato", "Montserrat"/"Open Sans".',
                'legibility' => 'lineHeight ~1.1–1.3× fontSize for headlines, ~1.4× for body. Never let '
                    .'text run edge to edge — constrain the box width and keep it inside the safe margin.',
                'autoResize' => 'Set autoResize:true on a headline whose text is overridden per snap '
                    .'(variant "text") so long copy shrinks to fit instead of overflowing. When '
                    .'autoResize is on, fontSize is ignored.',
                'alignment' => 'Pick left OR center for the whole snap and stick to it. Left-aligned '
                    .'reads as modern/editorial; centered as classic/announcement.',
            ],

            'color' => [
                'palette' => 'One dominant background color, one accent for the CTA/highlight, plus '
                    .'near-white and near-black for text. Four colors total is a strong ceiling.',
                'contrast' => 'Text vs its background must be high-contrast. Light text (#ffffff / '
                    .'#f5f5f5) on a dark fill, or dark text (#111827 / #1f2937) on a light fill. Do '
                    .'not put mid-tone text on a mid-tone background.',
                'gradients' => 'A subtle two-stop backgroundGradientColor (linear, ~135°) adds depth '
                    .'without noise. Keep the two stops within the same hue family.',
                'accent' => 'Use the accent color on exactly one element (the CTA pill or a key word) '
                    .'so it actually draws the eye.',
            ],

            'imagery_and_texture' => [
                'photos' => 'A backgroundImage must be a public URL. Cover the canvas (backgroundSize '
                    .'"cover"). If text sits on the photo, drop an overlay scrim or a dark gradient '
                    .'over it first — never text straight onto a busy photo.',
                'svg_patterns' => 'backgroundSvg patterns are decoration, not the star. Use one, '
                    .'full-canvas, at low opacity, colored near the base fill. See '
                    .'describe-layers → svg_pattern_designs for the exact names.',
                'effects' => 'shadow gives cards/pills lift (soft: y 8–16, blur 24–40, low-alpha '
                    .'color). filterBlur on a background image behind text improves legibility. Use '
                    .'filterBrightness/Contrast/Grayscale sparingly — one adjustment, not a stack.',
            ],

            'recipes' => [
                [
                    'name' => 'Promo / sale',
                    'layers' => 'base fill (bold color) → optional low-opacity pattern → eyebrow text '
                        .'("LIMITED TIME") → big headline ("40% OFF") → subhead (offer detail) → CTA '
                        .'pill (accent bg + short verb) → logo top corner.',
                ],
                [
                    'name' => 'Quote / testimonial card',
                    'layers' => 'soft gradient base → large opening quotation glyph or oversized quote '
                        .'text (autoResize) → attribution line (name, smaller/lighter) → small logo.',
                ],
                [
                    'name' => 'Event announcement',
                    'layers' => 'photo or gradient base → dark scrim → title → date + venue lines '
                        .'(aligned, same left edge) → CTA ("RSVP") → QR code (qr_code layer) linking '
                        .'to the signup.',
                ],
                [
                    'name' => 'Product feature',
                    'layers' => 'clean light base → product backgroundImage centered → short product '
                        .'name → one-line benefit → price/accent tag → logo.',
                ],
                [
                    'name' => 'Coupon / loyalty',
                    'layers' => 'branded base → headline offer → dashed-border code box (borderStyle '
                        .'"dashed") → fine-print expiry → barcode or QR for redemption.',
                ],
            ],

            'anti_patterns' => [
                'Text touching or bleeding off the canvas edge — always inside the safe margin.',
                'More than two font families, or more than three type sizes.',
                'Low-contrast text (grey on grey, white on a pale photo with no scrim).',
                'A loud SVG pattern at full opacity fighting the headline.',
                'Five stacked filters/shadows — pick one effect and commit.',
                'Overlapping layers with the same zIndex (paint order becomes undefined).',
                'Centering some elements and left-aligning others in the same snap.',
            ],

            'preflight_checklist' => [
                'Every text/logo/CTA layer sits inside the safe margin.',
                'Headline is the largest element and reads at a glance.',
                'Text contrasts its immediate background (scrim added over any photo).',
                '≤2 fonts, ≤4 colors, one accent used once.',
                'zIndex tiers are ordered base < texture < imagery < scrim < content < marks.',
                'Ran preview-template and checked it before the final render.',
            ],
        ];
    }
}

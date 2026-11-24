<?php

namespace App\Support;

use App\Models\Template;

/**
 * The four values a render needs: layers, groups, canvas size, and the company
 * whose custom fonts the layers can name.
 *
 * A template carries these values on a saved row. An inline design carries
 * them on a request. The renderer reads this object, so both sources take one
 * path. `layers` and `groups` hold the JSON string shape that
 * `SnapBuilder::setLayers()` consumes, the same shape `templates.layers` and
 * `template_versions.layers` store.
 */
final class InlineDesign
{
    public function __construct(
        public readonly string $layers,
        public readonly ?string $groups,
        public readonly int $width,
        public readonly int $height,
        public readonly ?int $companyId,
    ) {}

    /**
     * Read the renderable values off a template (published version first,
     * draft when the template was never published).
     */
    public static function fromTemplate(Template $template): self
    {
        return new self(
            layers: (string) ($template->renderableLayers() ?? '[]'),
            groups: $template->renderableGroups(),
            width: (int) $template->width,
            height: (int) $template->height,
            companyId: $template->company_id,
        );
    }

    /**
     * Build a design from validated tool input. `$layers` is the decoded array
     * the validation rules read; it is encoded here one time.
     *
     * @param  array<int, array<string, mixed>>  $layers
     */
    public static function fromInput(array $layers, int $width, int $height, ?int $companyId): self
    {
        return new self(
            layers: json_encode(array_values($layers)),
            groups: null,
            width: $width,
            height: $height,
            companyId: $companyId,
        );
    }
}

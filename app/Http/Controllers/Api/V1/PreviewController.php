<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Services\PreviewRenderer;
use App\Support\VariantRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Watermarked preview render.
 *
 * Deliberately NOT in the logApiRequest group: a preview is not a render, so
 * it stays out of the usage log. Abuse is bounded by throttle:preview. The response is a self-contained
 * HTML document — nothing is rasterized and nothing is persisted, so no public,
 * re-fetchable media URL is handed out.
 */
class PreviewController extends Controller
{
    public function template(Request $request, PreviewRenderer $renderer, string $templateUuid)
    {
        $request->validate(VariantRules::rules());

        $template = $this->scopedTemplate($templateUuid);

        return $this->stream($renderer->render($template, $request->input('variants', [])));
    }

    public function snap(Request $request, PreviewRenderer $renderer)
    {
        $validated = $request->validate([
            'template_id' => ['required', 'string'],
        ] + VariantRules::rules());

        $template = $this->scopedTemplate($validated['template_id']);

        return $this->stream($renderer->render($template, $request->input('variants', [])));
    }

    private function scopedTemplate(string $uuid): Template
    {
        return Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function stream(array $result)
    {
        return response($result['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Robots-Tag' => 'noindex',
            'X-Snap-Preview' => 'true',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\TemplateVersioning;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TemplateVersionController extends Controller
{
    public function __construct(private TemplateVersioning $versioning) {}

    public function index(Request $request, $templateId)
    {
        $template = $this->ownedTemplate($templateId);

        $validated = $request->validate([
            'cursor' => ['nullable', 'integer', 'min:1'],
        ]);

        $versions = $template->versions()
            ->with(['creator:id,name', 'media'])
            ->when($validated['cursor'] ?? null, function ($query, $cursor) {
                $query->where('version_number', '<', $cursor);
            })
            ->latest('version_number')
            ->limit(51)
            ->get(['id', 'version_number', 'published', 'created_by', 'created_at']);

        $hasMore = $versions->count() > 50;
        $versions = $versions->take(50)->values();

        return response()->json([
            'data' => $versions
                ->map(fn ($version) => [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'published' => $version->published,
                    'is_current' => $version->id === $template->published_version_id,
                    'created_at' => $version->created_at,
                    'author' => optional($version->creator)->name,
                    'preview_image' => $version->preview_image ?: null,
                ]),
            'next_cursor' => $hasMore ? $versions->last()->version_number : null,
            'published_version_id' => $template->published_version_id,
        ]);
    }

    public function publish($templateId)
    {
        $template = $this->ownedTemplate($templateId);

        $version = $this->versioning->publish($template);

        return response()->json([
            'message' => 'Template published.',
            'published_version_id' => $version->id,
            'version_number' => $version->version_number,
        ]);
    }

    public function revert($templateId, $versionId)
    {
        $template = $this->ownedTemplate($templateId);

        // IDOR guard: the version MUST belong to this (already company-scoped)
        // template — never trust a bare version id from the request.
        $version = TemplateVersion::where('id', $versionId)
            ->where('template_id', $template->id)
            ->firstOrFail();

        $this->versioning->revert($template, $version);

        return response()->json([
            'message' => 'Template reverted. Your previous draft was saved to history.',
            'reverted_to_version' => $version->version_number,
        ]);
    }

    public function preview($templateId, $versionId)
    {
        $template = $this->ownedTemplate($templateId);

        $version = TemplateVersion::where('id', $versionId)
            ->where('template_id', $template->id)
            ->firstOrFail();

        if (! $version->preview_image) {
            $version->generatePreview();
            $version->refresh();
        }

        return response()->json([
            'image' => $version->preview_image,
            'version_number' => $version->version_number,
        ]);
    }

    private function ownedTemplate($templateId): Template
    {
        $template = Template::where('company_id', Auth::user()->company_id)
            ->where('id', $templateId)
            ->firstOrFail();

        $this->authorize('update', $template);

        return $template;
    }
}

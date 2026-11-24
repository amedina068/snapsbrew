<?php

namespace App\Http\Controllers;

use App\Actions\Templates\DuplicateTemplate;
use App\Jobs\ProcessSnap;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Services\SnapSourceVersioner;
use App\Support\Queues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The in-app "create your own snap" flow — the active WOW moment after
 * onboarding: pick a template, customize its text, generate a real snap.
 *
 * Snaps here count in the usage log (is_async = true, so ProcessSnap records
 * one render on completion). Onboarding showcase snaps are NOT created
 * here — see App\Services\OnboardingSnapDispatcher.
 */
class SnapCreateController extends Controller
{
    public function create(string $templateId)
    {
        $template = $this->findUsableTemplate($templateId);
        $company = Auth::user()->company;

        return view('templates.generate', [
            'template' => $template,
            'textLayers' => $this->textLayers($template),
            // The welcome dialog is for the first snap of the company only.
            'firstSnap' => ! $company instanceof Company || $company->first_snap_at === null,
        ]);
    }

    public function store(string $templateId, Request $request, DuplicateTemplate $duplicate, SnapSourceVersioner $sourceVersioner): JsonResponse
    {
        $source = $this->findUsableTemplate($templateId);

        $request->validate([
            'fields' => ['array'],
            'fields.*' => ['nullable', 'string', 'max:280'],
        ]);

        // Always snap a company-owned template so the user keeps a reusable
        // copy. Public picker templates are cloned on first use.
        $template = $source->user_id === Auth::id()
            ? $source
            : $duplicate->execute($source, Auth::user());

        $variants = $this->buildVariants($request->input('fields', []));
        $snap = $sourceVersioner->createSnap(
            $template,
            function (Template $lockedTemplate, TemplateVersion $source) use ($variants) {
                return $lockedTemplate->snaps()->create([
                    'user_id' => Auth::id(),
                    'company_id' => Auth::user()->company_id,
                    'source_template_version_id' => $source->id,
                    'variants' => json_encode($variants),
                    'type' => Snap::TYPE_IMAGE,
                    'is_async' => true,
                    'status' => Snap::STATUS_PENDING,
                ]);
            }
        );

        ProcessSnap::dispatch($snap)->onQueue(Queues::RENDERS);

        return response()->json([
            'snap_uuid' => $snap->uuid,
            'status_url' => url("snaps/{$snap->uuid}/status"),
        ]);
    }

    public function status(string $snapUuid): JsonResponse
    {
        $snap = Snap::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($snapUuid)
            ->firstOrFail();

        return response()->json([
            'status' => $snap->status,
            'image_url' => $snap->isComplete() ? $snap->imageUrl : null,
        ]);
    }

    private function findUsableTemplate(string $templateId): Template
    {
        return Template::query()
            ->uuid($templateId)
            ->where(function ($query) {
                $query->whereNull('user_id')
                    ->orWhere('company_id', Auth::user()->company_id);
            })
            ->firstOrFail();
    }

    /**
     * Text layers exposed as customizable fields, keyed by layer key with the
     * layer's current text as the default value.
     *
     * @return array<string, string>
     */
    private function textLayers(Template $template): array
    {
        return collect(json_decode($template->layers, true) ?: [])
            ->filter(fn ($layer) => ($layer['type'] ?? null) === 'text')
            ->mapWithKeys(function ($layer) {
                $text = collect($layer['properties'] ?? [])->firstWhere('name', 'textContent');

                return [$layer['key'] => $text['value'] ?? ''];
            })
            ->all();
    }

    /**
     * @param  array<string, string|null>  $fields
     * @return array<int, array<string, string>>
     */
    private function buildVariants(array $fields): array
    {
        return collect($fields)
            ->filter(fn ($text) => $text !== null && $text !== '')
            ->map(fn ($text, $key) => ['layer' => $key, 'text' => $text])
            ->values()
            ->all();
    }
}

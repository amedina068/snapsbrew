<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Models\TemplateCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TemplateCollectionTemplateController extends Controller
{
    public function create($templateCollectionUuid)
    {
        $templateCollection = TemplateCollection::query()
            ->where('uuid', $templateCollectionUuid)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $excludedTemplateIds = $templateCollection->templates()->pluck('id');

        $templates = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->when($excludedTemplateIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $excludedTemplateIds))
            ->orderBy('title')
            ->get();

        $selectedTemplateUuids = collect(session()->getOldInput('template_ids', []))
            ->map(fn ($value) => (string) $value)
            ->toArray();

        return view('template-collection-templates.create', compact('templateCollection', 'templates', 'selectedTemplateUuids'));
    }

    public function store(Request $request, $templateCollectionUuid)
    {
        $validated = $request->validate([
            'template_ids' => ['required', 'array', 'min:1'],
            'template_ids.*' => [
                'string',
                Rule::exists('templates', 'uuid')
                    ->where('company_id', Auth::user()->company_id),
            ],
        ]);

        $templateCollection = TemplateCollection::query()
            ->where('uuid', $templateCollectionUuid)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $templates = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->whereIn('uuid', $validated['template_ids'])
            ->get();

        if ($templates->isEmpty()) {
            return back()->withErrors([
                'template_ids' => 'Select at least one template.',
            ]);
        }

        $timestamp = now();
        $attachData = $templates->pluck('id')->mapWithKeys(fn ($id) => [
            $id => ['created_at' => $timestamp, 'updated_at' => $timestamp],
        ])->toArray();

        $templateCollection->templates()->syncWithoutDetaching($attachData);

        return redirect('collections/'.$templateCollection->uuid)
            ->with('success', 'Templates added to the collection.');
    }

    public function destroy($templateCollectionUuid, $templateUuid)
    {
        $templateCollection = TemplateCollection::query()
            ->where('uuid', $templateCollectionUuid)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $template = $templateCollection->templates()->where('uuid', $templateUuid)->firstOrFail();

        $templateCollection->templates()->detach($template);

        return redirect('collections/'.$templateCollection->uuid)
            ->with('success', 'The template is removed from the collection.');
    }
}

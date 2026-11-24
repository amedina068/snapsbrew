<?php

namespace App\Http\Controllers;

use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TemplateCollectionController extends Controller
{
    public function index(Request $request)
    {
        $templateCollections = TemplateCollection::query()
            ->where('company_id', Auth::user()->company_id)
            ->withCount('templates')
            ->with(['templates.tags'])
            ->when(
                $request->filled('q'),
                fn ($query) => $query->where(
                    fn ($query) => $query
                        ->where('name', 'like', "%{$request->q}%")
                        ->orWhereHas(
                            'templates',
                            fn ($templateQuery) => $templateQuery
                                ->where('title', 'like', "%{$request->q}%")
                                ->orWhereHas(
                                    'tags',
                                    fn ($tagQuery) => $tagQuery->where('name', 'like', "%{$request->q}%")
                                )
                        )
                )
            )
            ->orderBy('name')
            ->get()
            ->each(function ($collection) {
                $collection->templates->each->append('preview_image');
            });

        return view('template-collections.index', [
            'templateCollections' => $templateCollections,
            'search' => $request->q,
        ]);
    }

    public function show($templateCollectionUuid)
    {
        $templateCollection = TemplateCollection::query()
            ->where('uuid', $templateCollectionUuid)
            ->where('company_id', Auth::user()->company_id)
            ->with('templates.tags')
            ->firstOrFail();

        $templates = $templateCollection->templates;
        $templates->each->append('preview_image');

        $forms = $templateCollection->forms()
            ->latest('id')
            ->take(6)
            ->get();

        $templateIds = $templates->pluck('id');

        $snaps = $templateIds->isNotEmpty()
            ? Snap::query()
                ->whereIn('template_id', $templateIds)
                ->latest('id')
                ->take(9)
                ->get()
            : collect();

        return view('template-collections.show', compact('templateCollection', 'templates', 'forms', 'snaps'));
    }

    public function create()
    {
        $company = Auth::user()->company;

        $templates = $company
            ? $company->templates()->orderBy('title')->get()
            : collect();

        $selectedTemplateUuids = collect(session()->getOldInput('template_ids', []))
            ->map(fn ($value) => (string) $value)
            ->toArray();

        return view('template-collections.create', compact('templates', 'selectedTemplateUuids'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string'],
            'template_ids' => ['array'],
            'template_ids.*' => [
                'string',
                Rule::exists('templates', 'uuid')
                    ->where('company_id', Auth::user()->company_id),
            ],
        ]);

        $templateCollection = TemplateCollection::create([
            'user_id' => Auth::id(),
            'company_id' => Auth::user()->company_id,
            'name' => $request->name,
        ]);

        if ($request->filled('template_ids')) {
            $templateUuids = array_unique($request->input('template_ids', []));

            $templateIds = Template::query()
                ->where('company_id', Auth::user()->company_id)
                ->whereIn('uuid', $templateUuids)
                ->pluck('id');

            if ($templateIds->isNotEmpty()) {
                $timestamp = now();
                $attachData = $templateIds->mapWithKeys(fn ($id) => [$id => ['created_at' => $timestamp, 'updated_at' => $timestamp]])->toArray();
                $templateCollection->templates()->syncWithoutDetaching($attachData);
            }
        }

        return redirect('collections/'.$templateCollection->uuid)
            ->with('success', 'Collection created successfully.');
    }

    public function destroy($templateCollectionUuid)
    {
        $templateCollection = TemplateCollection::query()
            ->where('uuid', $templateCollectionUuid)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $templateCollection->delete();

        return redirect('collections')->with('status', "The collection \"{$templateCollection->name}\" is deleted.");
    }
}

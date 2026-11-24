<?php

namespace App\Http\Controllers;

use App\Actions\Templates\DuplicateTemplate;
use App\Models\Form;
use App\Models\Snap;
use App\Models\Tag;
use App\Models\Template;
use App\Support\TemplateSizes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $companyId = Auth::user()->company_id;

        $selectedTagSlugs = collect($request->input('tags', []))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $templates = Template::query()
            ->where('company_id', $companyId)
            ->with(['tags'])
            ->when($request->filled('size'), function ($query) use ($request) {
                $query->where('size', $request->size);
            })
            ->when($request->filled('q'), function ($query) use ($request) {
                $search = trim($request->q);
                $query->where(function ($subQuery) use ($search) {
                    $subQuery->where('title', 'like', "%{$search}%")
                        ->orWhereHas('tags', function ($tagQuery) use ($search) {
                            $tagQuery->where('name', 'like', "%{$search}%");
                        });
                });
            })
            ->when(! empty($selectedTagSlugs), function ($query) use ($selectedTagSlugs) {
                foreach ($selectedTagSlugs as $slug) {
                    $query->whereHas('tags', function ($tagQuery) use ($slug) {
                        $tagQuery->where('slug', $slug);
                    });
                }
            })
            ->orderByDesc('updated_at')
            ->paginate(12)
            ->withQueryString();

        $sizes = TemplateSizes::grouped();

        $availableTags = Tag::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();

        return view('templates.index', [
            'templates' => $templates,
            'sizes' => $sizes,
            'availableTags' => $availableTags,
            'selectedTagSlugs' => $selectedTagSlugs,
            'searchQuery' => $request->q,
        ]);
    }

    public function show($templateUuid)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($templateUuid)
            ->firstOrFail();

        $templateLayers = collect(json_decode($template->layers, true));

        $snaps = Snap::query()
            ->where('template_id', $template->id)
            ->latest('id')
            ->paginate(15);

        $forms = Form::query()
            ->where('company_id', Auth::user()->company_id)
            ->forFormable($template)
            ->latest('id')
            ->take(5)
            ->get();

        $latestImports = $template->imports()
            ->latest('id')
            ->take(5)
            ->get();

        return view('templates.show', compact('template', 'snaps', 'templateLayers', 'forms', 'latestImports'));
    }

    public function create(Request $request)
    {
        $templates = Template::query()
            ->public()
            ->when($request->filled('size'), function ($query) use ($request) {
                $query->where('size', $request->size);
            })
            ->get();

        $sizes = TemplateSizes::grouped();

        return view('templates.create', compact('templates', 'sizes'));
    }

    public function store(Request $request, DuplicateTemplate $duplicateTemplate)
    {
        $request->validate([
            'template_id' => [
                'required',
                'integer',
                Rule::exists('templates', 'id')->where(function ($query) {
                    $query->whereNull('user_id')->orWhere('user_id', Auth::id());
                }),
            ],
        ]);

        $template = Template::find($request->template_id);

        $newTemplate = $duplicateTemplate->execute($template, Auth::user());

        return redirect("templates/{$newTemplate->uuid}");
    }

    public function destroy($templateUuid)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($templateUuid)
            ->firstOrFail();

        $this->authorize('delete', $template);

        $template->delete();

        return redirect('/templates')->with('status', "The template \"{$template->title}\" is deleted.");
    }

    public function edit($templateUuid)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($templateUuid)
            ->firstOrFail();

        $selectedDefaultSize = TemplateSizes::find($template->size)
            ?? TemplateSizes::matchDimensions((int) $template->width, (int) $template->height);

        $defaultSizes = TemplateSizes::grouped();

        return view('templates.edit', compact('template', 'selectedDefaultSize', 'defaultSizes'));
    }

    public function update(Request $request, $templateUuid)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($templateUuid)
            ->firstOrFail();

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'size' => ['required', 'string', Rule::in(TemplateSizes::values())],
            'width' => ['required_if:size,custom', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'height' => ['required_if:size,custom', 'numeric', 'min:1', 'max:'.Template::MAX_DIMENSION],
        ]);

        $selectedDefaultSize = TemplateSizes::find($request->size);

        $changes = [
            'title' => $request->title,
            'width' => $selectedDefaultSize ? $selectedDefaultSize['width'] : $request->width,
            'height' => $selectedDefaultSize ? $selectedDefaultSize['height'] : $request->height,
        ];

        // Write `size` only when the caller named a preset. The form sends
        // `custom` for every shape the select does not name, and a resolve here
        // reached `Template::booted()` as the caller's own choice. A template
        // stored at `tiktok_vertical` came back `instagram_story`, because
        // seven presets share 1080x1920 and the hook keeps the stored one.
        if ($selectedDefaultSize) {
            $changes['size'] = $selectedDefaultSize['value'];
        }

        $template->fill($changes)->saveWithPreview();

        if ($request->editor) {
            return redirect("/templates/{$templateUuid}/editor")->with('success', 'Template updated successfully');
        }

        return redirect('/templates/'.$templateUuid)->with('success', 'Template updated successfully');
    }
}

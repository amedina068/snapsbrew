<?php

namespace App\Http\Controllers;

use App\Builders\FormLayerBuilder;
use App\Models\Form;
use App\Models\Snap;
use App\Models\TemplateCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class TemplateCollectionFormController extends Controller
{
    public function index($templateCollectionUuid)
    {
        $templateCollection = $this->getTemplateCollection($templateCollectionUuid);

        $hasLayers = $this->collectionHasLayers($templateCollection);

        $forms = Form::query()
            ->forFormable($templateCollection)
            ->where('forms.company_id', Auth::user()->company_id)
            ->leftJoin('snaps', 'forms.id', 'snaps.form_id')
            ->groupBy('forms.id')
            ->select('forms.*', DB::raw('COUNT(snaps.id) as snaps_count'))
            ->paginate(12);

        return view('template-collections.forms.index', compact('templateCollection', 'forms', 'hasLayers'));
    }

    public function create($templateCollectionUuid)
    {
        $templateCollection = $this->getTemplateCollection($templateCollectionUuid);

        $templates = $templateCollection->templates()->orderBy('title')->get();

        $templatesWithLayers = $templates->map(function ($template) {
            return [
                'template' => $template,
                'layers' => json_decode($template->layers, true) ?? [],
            ];
        });

        $hasLayers = $templatesWithLayers
            ->pluck('layers')
            ->filter(fn (array $layers) => ! empty($layers))
            ->isNotEmpty();

        if (! $hasLayers) {
            return redirect("collections/{$templateCollection->uuid}/forms")
                ->with('error', 'Add at least one layer to the templates in this collection before creating a form.');
        }

        return view('template-collections.forms.create', compact('templateCollection', 'templatesWithLayers'));
    }

    public function store(Request $request, $templateCollectionUuid)
    {
        $templateCollection = $this->getTemplateCollection($templateCollectionUuid);

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'form_layers' => ['required', 'array'],
            'form_layers.*' => ['array'],
            'type' => ['required', 'string', 'in:pdf,image'],
        ], [
            'form_layers.required' => 'Select at least one layer across the collection templates.',
        ]);

        $selectedLayers = $this->resolveSelectedLayers(
            $templateCollection,
            $request->input('form_layers', [])
        );

        if (empty($selectedLayers)) {
            return back()
                ->withInput()
                ->withErrors([
                    'form_layers' => 'Select at least one layer across the collection templates.',
                ]);
        }

        $form = Form::create([
            'user_id' => Auth::id(),
            'company_id' => Auth::user()->company_id,
            'formable_id' => $templateCollection->id,
            'formable_type' => TemplateCollection::class,
            'title' => $request->title,
            'type' => $request->type,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => $selectedLayers,
            ]),
        ]);

        $form->update([
            'signed_url' => URL::signedRoute('signedEmbeddedForm', ['formUuid' => $form->uuid]),
            'submit_signed_url' => URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]),
        ]);

        return redirect('collections/'.$templateCollection->uuid.'/forms/'.$form->uuid)
            ->with('success', 'Your form was created successfully');
    }

    public function show($templateCollectionUuid, $formUuid)
    {
        $templateCollection = $this->getTemplateCollection($templateCollectionUuid);

        $form = Form::query()
            ->uuid($formUuid)
            ->forFormable($templateCollection)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        if ($form->scope() !== Form::SCOPE_COLLECTION) {
            abort(404);
        }

        $snaps = Snap::query()
            ->where('form_id', $form->id)
            ->with('template')
            ->paginate(12);

        foreach ($snaps as $snap) {
            $snap->append('imagePath');
        }

        $formLayerSummaries = $this->buildFormLayerSummaries($templateCollection, $form->configuredLayers());

        return view('template-collections.forms.show', compact(
            'templateCollection',
            'form',
            'snaps',
            'formLayerSummaries'
        ));
    }

    public function edit($templateCollectionUuid, $formUuid)
    {
        $templateCollection = $this->getTemplateCollection($templateCollectionUuid);

        $form = Form::query()
            ->uuid($formUuid)
            ->forFormable($templateCollection)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        if ($form->scope() !== Form::SCOPE_COLLECTION) {
            abort(404);
        }

        $templates = $templateCollection->templates()->orderBy('title')->get();

        $templatesWithLayers = $templates->map(function ($template) {
            return [
                'template' => $template,
                'layers' => json_decode($template->layers, true) ?? [],
            ];
        });

        $selectedLayers = collect($form->configuredLayers())
            ->mapWithKeys(function (array $configuration) {
                return [$configuration['template_uuid'] => $configuration['layer_keys'] ?? []];
            })->toArray();

        return view('template-collections.forms.edit', compact(
            'templateCollection',
            'form',
            'templatesWithLayers',
            'selectedLayers'
        ));
    }

    public function update(Request $request, $templateCollectionUuid, $formUuid)
    {
        $templateCollection = $this->getTemplateCollection($templateCollectionUuid);

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'form_layers' => ['required', 'array'],
            'form_layers.*' => ['array'],
            'type' => ['required', 'string', 'in:pdf,image'],
        ], [
            'form_layers.required' => 'Select at least one layer across the collection templates.',
        ]);

        $form = Form::query()
            ->uuid($formUuid)
            ->forFormable($templateCollection)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        if ($form->scope() !== Form::SCOPE_COLLECTION) {
            abort(404);
        }

        $selectedLayers = $this->resolveSelectedLayers(
            $templateCollection,
            $request->input('form_layers', [])
        );

        if (empty($selectedLayers)) {
            return back()
                ->withInput()
                ->withErrors([
                    'form_layers' => 'Select at least one layer across the collection templates.',
                ]);
        }

        $form->update([
            'title' => $request->title,
            'type' => $request->type,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_COLLECTION,
                'layers' => $selectedLayers,
            ]),
        ]);

        return redirect('collections/'.$templateCollection->uuid.'/forms/'.$form->uuid)
            ->with('success', 'Your form was updated successfully');
    }

    private function getTemplateCollection($uuid): TemplateCollection
    {
        return TemplateCollection::query()
            ->where('uuid', $uuid)
            ->where('company_id', Auth::user()->company_id)
            ->with('templates')
            ->firstOrFail();
    }

    private function collectionHasLayers(TemplateCollection $templateCollection): bool
    {
        return $templateCollection->templates
            ->contains(function ($template) {
                $layers = json_decode($template->layers, true) ?? [];

                return ! empty($layers);
            });
    }

    private function resolveSelectedLayers(TemplateCollection $templateCollection, array $formLayers): array
    {
        $templates = $templateCollection->templates->keyBy('uuid');

        $selectedLayers = [];

        foreach ($formLayers as $templateUuid => $layers) {
            if (! $templates->has($templateUuid)) {
                continue;
            }

            $template = $templates->get($templateUuid);

            $availableLayerKeys = collect(json_decode($template->layers, true) ?? [])
                ->pluck('key')
                ->toArray();

            $layerKeys = array_keys($layers);
            $validLayerKeys = array_values(array_intersect($layerKeys, $availableLayerKeys));

            if (! empty($validLayerKeys)) {
                $selectedLayers[] = [
                    'template_uuid' => $templateUuid,
                    'layer_keys' => $validLayerKeys,
                ];
            }
        }

        return $selectedLayers;
    }

    private function buildFormLayerSummaries(TemplateCollection $templateCollection, array $configuredLayers): Collection
    {
        $templates = $templateCollection->templates->keyBy('uuid');

        return collect($configuredLayers)
            ->map(function (array $configuration) use ($templates) {
                $template = $templates->get($configuration['template_uuid'] ?? null);

                if (! $template) {
                    return null;
                }

                $templateLayers = collect(json_decode($template->layers, true) ?? []);

                $layers = collect($configuration['layer_keys'] ?? [])
                    ->map(function ($layerKey) use ($templateLayers) {
                        $layer = $templateLayers->firstWhere('key', $layerKey);

                        if (! $layer) {
                            return null;
                        }

                        return (new FormLayerBuilder($layer))->parse();
                    })
                    ->filter()
                    ->values();

                if ($layers->isEmpty()) {
                    return null;
                }

                return [
                    'template' => $template,
                    'layers' => $layers,
                ];
            })
            ->filter()
            ->values();
    }
}

<?php

namespace App\Http\Controllers;

use App\Builders\FormLayerBuilder;
use App\Models\Form;
use App\Models\Snap;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class FormController extends Controller
{
    public function index($templateUuid)
    {
        $template = $this->getTemplate($templateUuid);

        $templateLayers = json_decode($template->layers, true) ?? [];
        $hasLayers = ! empty($templateLayers);

        $forms = Form::query()
            ->forFormable($template)
            ->where('forms.company_id', Auth::user()->company_id)
            ->leftJoin('snaps', 'forms.id', 'snaps.form_id')
            ->groupBy('forms.id')
            ->select('forms.*', DB::raw('COUNT(snaps.id) as snaps_count'))
            ->paginate(12);

        return view('forms.index', compact('template', 'forms', 'hasLayers'));
    }

    public function show($templateUuid, $formUuid)
    {
        $template = $this->getTemplate($templateUuid);

        $form = Form::query()
            ->uuid($formUuid)
            ->forFormable($template)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        $snaps = Snap::query()->where('form_id', $form->id)->paginate(12);

        foreach ($snaps as $snap) {
            $snap->append('imagePath');
        }

        $configuredLayerKeys = $form->configuredLayers();

        $formLayers = collect(json_decode($template->layers, true))
            ->filter(function ($layer) use ($configuredLayerKeys) {
                return in_array($layer['key'], $configuredLayerKeys);
            })->map(function ($layer) {
                return (new FormLayerBuilder($layer))->parse();
            })->values();

        return view('forms.show', compact('template', 'form', 'snaps', 'formLayers'));
    }

    public function create($templateUuid)
    {
        $template = $this->getTemplate($templateUuid);

        $layers = json_decode($template->layers, true) ?? [];

        if (empty($layers)) {
            return redirect("templates/{$template->uuid}/forms")
                ->with('error', 'Add at least one layer to the template before creating a form.');
        }

        return view('forms.create', compact('layers', 'template'));
    }

    public function store(Request $request, $templateUuid)
    {
        $template = $this->getTemplate($templateUuid);

        $templateLayers = json_decode($template->layers, true) ?? [];

        if (empty($templateLayers)) {
            return redirect("templates/{$templateUuid}/forms")
                ->with('error', 'Add at least one layer to the template before creating a form.');
        }

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'form_layers' => ['required', 'array', 'min:1'],
            'type' => ['required', 'string', 'in:pdf,image'],
        ], [
            'form_layers.required' => 'Select at least one layer.',
        ]);

        $selectedLayerKeys = array_keys($request->form_layers);

        $form = Form::create([
            'user_id' => Auth::id(),
            'company_id' => Auth::user()->company_id,
            'formable_id' => $template->id,
            'formable_type' => Template::class,
            'title' => $request->title,
            'type' => $request->type,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_TEMPLATE,
                'layers' => $selectedLayerKeys,
            ]),
        ]);

        $form->update([
            'signed_url' => URL::signedRoute('signedEmbeddedForm', ['formUuid' => $form->uuid]),
            'submit_signed_url' => URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]),
        ]);

        return redirect('templates/'.$templateUuid.'/forms/'.$form->uuid)->with('success', 'Your form was created successfully');
    }

    public function edit($templateUuid, $formUuid)
    {
        $template = $this->getTemplate($templateUuid);

        $form = Form::query()
            ->uuid($formUuid)
            ->forFormable($template)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        if ($form->scope() !== Form::SCOPE_TEMPLATE) {
            abort(404);
        }

        $layers = json_decode($template->layers, true);
        $selectedLayers = $form->configuredLayers();

        return view('forms.edit', compact('layers', 'template', 'form', 'selectedLayers'));
    }

    public function update(Request $request, $templateUuid, $formUuid)
    {
        $template = $this->getTemplate($templateUuid);

        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'form_layers' => ['required', 'array', 'min:1'],
            'type' => ['required', 'string', 'in:pdf,image'],
        ], [
            'form_layers.required' => 'Select at least one layer.',
        ]);

        $form = Form::query()
            ->uuid($formUuid)
            ->forFormable($template)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        if ($form->scope() !== Form::SCOPE_TEMPLATE) {
            abort(404);
        }

        $selectedLayerKeys = array_keys($request->form_layers);

        $form->update([
            'title' => $request->title,
            'available_layers' => json_encode([
                'scope' => Form::SCOPE_TEMPLATE,
                'layers' => $selectedLayerKeys,
            ]),
            'type' => $request->type,
        ]);

        return redirect('templates/'.$templateUuid.'/forms/'.$form->uuid)->with('success', 'Your form was updated successfully');
    }

    private function getTemplate($templateUuid): Template
    {
        return Template::query()
            ->uuid($templateUuid)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();
    }
}

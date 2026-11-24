<?php

namespace App\Http\Controllers;

use App\Builders\FormLayerBuilder;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Support\HostIndependentSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class EmbeddedFormController extends Controller
{
    public function show(Request $request, $formUuid)
    {
        if (! HostIndependentSignature::valid($request)) {
            return abort(401);
        }

        $form = Form::query()->with('user')->uuid($formUuid)->firstOrFail();

        // A deactivated owner cannot serve the form.
        abort_if((bool) $form->user?->isDeactivated(), 404);

        $formable = $form->formable;

        if ($form->isForTemplate()) {
            $layers = $this->buildTemplateLayers($form, $formable);
        } elseif ($form->isForCollection()) {
            $layers = $this->buildCollectionLayers($form, $formable);
        } else {
            abort(404);
        }

        // The page is public, so it gets the fields that the widget reads and
        // nothing else. The Form model carries its owner and its templates as
        // loaded relations, and a JSON copy of the model printed both.
        $props = [
            'title' => $form->title,
            // The stored URL names the host that created the form. A POST from
            // this page to an old host gets a 308, and a CORS preflight cannot
            // follow a redirect. Sign the submit URL for the host that serves
            // this page.
            'submit_signed_url' => URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]),
        ];

        return view('embedded-forms.show', ['form' => $props, 'layers' => $layers]);
    }

    private function buildTemplateLayers(Form $form, Template $template)
    {
        $formLayers = $form->configuredLayers();

        return collect(json_decode($template->layers, true))
            ->filter(function ($layer) use ($formLayers) {
                return in_array($layer['key'], $formLayers);
            })->map(function ($layer) {
                return (new FormLayerBuilder($layer))->parse();
            })->values();
    }

    private function buildCollectionLayers(Form $form, TemplateCollection $templateCollection)
    {
        $configuredLayers = collect($form->configuredLayers());

        $templates = $templateCollection->templates->keyBy('uuid');

        return $configuredLayers
            ->flatMap(function (array $configuration) use ($templates) {
                $template = $templates->get($configuration['template_uuid'] ?? null);

                if (! $template) {
                    return collect();
                }

                $templateLayers = collect(json_decode($template->layers, true) ?? []);

                return collect($configuration['layer_keys'] ?? [])
                    ->map(function ($layerKey) use ($templateLayers, $template) {
                        $layer = $templateLayers->firstWhere('key', $layerKey);

                        if (! $layer) {
                            return null;
                        }

                        $parsedLayer = (new FormLayerBuilder($layer))->parse();
                        $parsedLayer['original_key'] = $parsedLayer['key'];
                        $parsedLayer['template_uuid'] = $template->uuid;
                        $parsedLayer['template_title'] = $template->title;
                        $parsedLayer['label'] = $template->title.' — '.$parsedLayer['key'];
                        $parsedLayer['key'] = $template->uuid.'__'.$parsedLayer['key'];

                        return $parsedLayer;
                    })
                    ->filter();
            })
            ->values();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Builders\SnapBuilder;
use App\Http\Controllers\Controller;
use App\Http\Resources\SnapResource;
use App\Models\ApiLog;
use App\Models\Form;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Models\User;
use App\Services\UsageRecorder;
use App\Support\HostIndependentSignature;
use App\Support\ImageUpload;
use App\Support\VariantRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmbeddedFormController extends Controller
{
    /**
     * The variant key that each layer type of a form fills.
     */
    private const VALUE_KEYS = [
        'image' => 'image_url',
        'text' => 'text',
        'empty' => 'background_color',
        'qr_code' => 'qr_content',
        'barcode' => 'barcode_content',
    ];

    public function store(Request $request, $formUuid)
    {
        if (! HostIndependentSignature::valid($request)) {
            return abort(404);
        }

        $form = Form::query()
            ->with(['user'])
            ->uuid($formUuid)
            ->firstOrFail();

        if ($form->isForTemplate()) {
            return $this->handleTemplateForm($request, $form, $form->formable, $form->user);
        }

        return $this->handleCollectionForm($request, $form, $form->formable, $form->user);
    }

    private function generateSnap($template, $variants, $form, $user)
    {
        $snapBuilder = (new SnapBuilder)
            ->setLayers($template->renderableLayers())
            ->setGroups($template->renderableGroups())
            ->forCompany($template->company_id)
            ->setWidth($template->width)
            ->setHeight($template->height)
            ->withVariables($variants);

        $snap = $template->snaps()->create([
            'user_id' => $template->user_id,
            'company_id' => $template->company_id,
            'form_id' => $form->id,
            'type' => $form->type,
            'variants' => json_encode($variants),
        ]);

        $snap->addContent($snapBuilder->buildContent());
        $snap->addHead($snapBuilder->buildHead());

        $snap->{$form->type == 'pdf' ? 'getPdf' : 'getImage'}();

        activity()
            ->performedOn($snap)
            ->withProperties([
                'layers' => json_decode($template->layers, true),
                'width' => $template->width,
                'height' => $template->height,
                'variants' => $variants,
            ])
            ->causedBy($user)
            ->log('created');

        // SnapResource reads both: the template for its name, the source
        // version for the rendered size.
        $snap->load(['template', 'sourceTemplateVersion']);

        return $snap->append('imageUrl');
    }

    private function handleTemplateForm(Request $request, Form $form, Template $template, User $user)
    {
        $templateLayers = collect(json_decode($template->layers, true));

        $allowedLayerKeys = $form->configuredLayers();

        $layers = collect($request->layers ?? [])
            ->filter(function ($layer, $key) use ($allowedLayerKeys) {
                return in_array($key, $allowedLayerKeys);
            });

        $this->validateLayerValues($layers->map(function ($value, $key) use ($templateLayers) {
            return [
                'type' => $templateLayers->firstWhere('key', $key)['type'] ?? null,
                'value' => $value,
            ];
        })->all());

        $layers = $layers
            ->map(function ($layer, $key) use ($templateLayers, $user) {
                $templateLayer = $templateLayers->first(function ($templateLayer) use ($key) {
                    return $key == $templateLayer['key'];
                });

                if (! $templateLayer) {
                    return null;
                }

                $valueKey = self::VALUE_KEYS[$templateLayer['type']] ?? null;

                if (! $valueKey) {
                    return null;
                }

                $value = $layer;

                if ($templateLayer['type'] == 'image') {
                    $value = ImageUpload::addTo($user, $layer, 'imagesMedia')->getUrl('image');
                }

                return [
                    'layer' => $key,
                    $valueKey => $value,
                ];
            })
            ->filter()
            ->values();

        $snap = $this->generateSnap($template, $layers, $form, $user);

        $apiLog = ApiLog::uuid($request->api_log_uuid)->first();

        $response = new SnapResource($snap);

        if ($apiLog) {
            UsageRecorder::settle(
                $apiLog,
                renders: 1,
                feature: UsageRecorder::FEATURE_FORM,
                type: $form->type,
                statusCode: 201,
                response: $response,
            );
        }

        return response()
            ->json($response, 201)
            ->withHeaders([
                'X-Request-Uuid' => optional($apiLog)->uuid,
            ]);
    }

    private function handleCollectionForm(Request $request, Form $form, TemplateCollection $templateCollection, User $user)
    {
        $templates = $templateCollection->templates->keyBy('uuid');

        $configuration = collect($form->configuredLayers())
            ->mapWithKeys(function (array $config) use ($templates) {
                $template = $templates->get($config['template_uuid'] ?? null);

                if (! $template) {
                    return [];
                }

                return [
                    $template->uuid => [
                        'template' => $template,
                        'layer_keys' => $config['layer_keys'] ?? [],
                    ],
                ];
            });

        if ($configuration->isEmpty()) {
            return response()->json([
                'message' => 'The form does not include any templates.',
            ], 422);
        }

        $allowedLayers = $configuration->flatMap(function (array $config) {
            $template = $config['template'];
            $templateLayers = collect(json_decode($template->layers, true) ?? []);

            return collect($config['layer_keys'])
                ->map(function ($layerKey) use ($template, $templateLayers) {
                    $layer = $templateLayers->firstWhere('key', $layerKey);

                    if (! $layer) {
                        return null;
                    }

                    return [
                        'request_key' => $template->uuid.'__'.$layerKey,
                        'template' => $template,
                        'layer_key' => $layerKey,
                        'type' => $layer['type'],
                    ];
                })
                ->filter();
        })->keyBy('request_key');

        $requestLayers = collect($request->layers ?? []);

        $this->validateLayerValues($allowedLayers
            ->filter(fn (array $layerConfig, string $requestKey) => $requestLayers->has($requestKey))
            ->map(fn (array $layerConfig, string $requestKey) => [
                'type' => $layerConfig['type'],
                'value' => $requestLayers->get($requestKey),
            ])
            ->all());

        $processedLayers = $configuration->map(function () {
            return collect();
        });

        foreach ($allowedLayers as $requestKey => $layerConfig) {
            if (! $requestLayers->has($requestKey)) {
                continue;
            }

            $value = $requestLayers->get($requestKey);
            $template = $layerConfig['template'];
            $layerType = $layerConfig['type'];
            $layerKey = $layerConfig['layer_key'];

            $valueKey = self::VALUE_KEYS[$layerType] ?? null;

            if (! $valueKey) {
                continue;
            }

            if ($layerType === 'image') {
                $value = ImageUpload::addTo($user, $value, 'imagesMedia')->getUrl('image');
            }

            $processedLayers[$template->uuid] = $processedLayers[$template->uuid]
                ->push([
                    'layer' => $layerKey,
                    $valueKey => $value,
                ]);
        }

        $snaps = $configuration->map(function (array $config, string $templateUuid) use ($processedLayers, $form, $user) {
            /** @var Template $template */
            $template = $config['template'];
            $variants = $processedLayers[$templateUuid];

            return $this->generateSnap(
                $template,
                $variants->values(),
                $form,
                $user
            );
        })->values();

        $apiLog = ApiLog::uuid($request->api_log_uuid)->first();

        $response = $snaps->map(function ($snap) {
            return (new SnapResource($snap))->resolve();
        })->values();

        if ($apiLog) {
            UsageRecorder::settle(
                $apiLog,
                renders: $snaps->count(),
                feature: UsageRecorder::FEATURE_FORM,
                type: $form->type,
                statusCode: 201,
                response: $response,
            );
        }

        return response()
            ->json($response, 201)
            ->withHeaders([
                'X-Request-Uuid' => optional($apiLog)->uuid,
            ]);
    }

    /**
     * Checks each submitted value before the form stores a file or renders.
     * The submit URL is public, so a guest controls every value. An image
     * layer takes an uploaded image file only. The other layer types take
     * the same rules as the matching `variants` key of the REST API.
     *
     * @param  array<string, array{type: ?string, value: mixed}>  $values  keyed by the request key
     *
     * @throws ValidationException
     */
    private function validateLayerValues(array $values): void
    {
        $variantRules = VariantRules::rules('variants');
        $errors = [];

        foreach ($values as $requestKey => ['type' => $type, 'value' => $value]) {
            $valueKey = self::VALUE_KEYS[$type] ?? null;

            if (! $valueKey) {
                continue;
            }

            // An empty text field arrives as null, and the form passed it on
            // before this check, so null stays valid for the text types.
            $rules = $type === 'image'
                ? ['required', ...ImageUpload::rules()]
                : ['nullable', ...array_filter(
                    $variantRules["variants.*.{$valueKey}"],
                    fn ($rule) => $rule !== 'sometimes',
                )];

            $attribute = "layers.{$requestKey}";
            $validator = Validator::make(['value' => $value], ['value' => $rules], [], ['value' => $attribute]);

            if ($validator->fails()) {
                $errors[$attribute] = $validator->errors()->get('value');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}

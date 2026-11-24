<?php

namespace App\Http\Controllers\Api\V1\Tools;

use App\Builders\SnapBuilder;
use App\Http\Controllers\Controller;
use App\Jobs\RemoveFreeToolSnap;
use App\Support\FreeToolTemplate;
use Illuminate\Http\Request;

class TestimonialImageCreatorController extends Controller
{
    private const LAYER_KEYS = ['author', 'testimonial'];

    public function store(Request $request)
    {
        $request->validate([
            'author' => ['nullable', 'string', 'max:200'],
            'testimonial' => ['nullable', 'string', 'max:1000'],
        ]);

        $template = FreeToolTemplate::find('testimonial');

        if (! $template) {
            return FreeToolTemplate::unavailable();
        }

        // Only these layer keys change. A request cannot change another layer.
        $params = $this->parseRequestToParams(array_filter(
            $request->only(self::LAYER_KEYS),
            fn ($value) => $value !== null,
        ));

        $snapBuilder = (new SnapBuilder)
            ->setLayers($template->renderableLayers())
            ->setGroups($template->renderableGroups())
            ->forCompany($template->company_id)
            ->setWidth($template->width)
            ->setHeight($template->height)
            ->withVariables($params);

        $snap = $template->snaps()->create([
            'user_id' => $template->user_id,
            'company_id' => $template->company_id,
        ]);

        $snap->addContent($snapBuilder->buildContent());
        $snap->addHead($snapBuilder->buildHead());

        $snap->getImage();

        if (config('app.env') == 'production') {
            RemoveFreeToolSnap::dispatch($snap)
                ->delay(now()->addDays(1));
        }

        return response()->json([
            'image_path' => $snap->imageUrl,
        ]);
    }

    private function parseRequestToParams(array $requestData)
    {
        $params = array_reduce(array_keys($requestData), function ($carry, $key) use ($requestData) {
            array_push($carry, [
                'layer' => $key,
                'text' => $requestData[$key],
            ]);

            return $carry;
        }, []);

        return $params;
    }
}

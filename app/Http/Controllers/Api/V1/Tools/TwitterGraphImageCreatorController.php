<?php

namespace App\Http\Controllers\Api\V1\Tools;

use App\Builders\SnapBuilder;
use App\Http\Controllers\Controller;
use App\Jobs\RemoveFreeToolSnap;
use App\Models\User;
use App\Support\FreeToolTemplate;
use App\Support\ImageUpload;
use Illuminate\Http\Request;

class TwitterGraphImageCreatorController extends Controller
{
    private const LAYER_KEYS = ['title', 'website'];

    /** The free tool takes a smaller image than the authenticated API. */
    private const MAX_IMAGE_KILOBYTES = 2048;

    public function store(Request $request)
    {
        $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'website' => ['nullable', 'string', 'max:200'],
            'image' => ['required', ...ImageUpload::rules(self::MAX_IMAGE_KILOBYTES)],
        ]);

        $template = FreeToolTemplate::find('twitter_graph');

        if (! $template) {
            return FreeToolTemplate::unavailable();
        }

        // Only these layer keys change. A request cannot change another layer.
        $params = $this->parseRequestToParams(array_filter(
            $request->only(self::LAYER_KEYS),
            fn ($value) => $value !== null,
        ), $request->image);

        $media = $params['media'];
        $params = $params['params'];

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
            RemoveFreeToolSnap::dispatch($snap, $media)
                ->delay(now()->addDays(1));
        }

        return response()->json([
            'image_path' => $snap->imageUrl,
        ]);
    }

    private function parseRequestToParams(array $requestData, $image)
    {
        $params = array_reduce(array_keys($requestData), function ($carry, $key) use ($requestData) {
            array_push($carry, [
                'layer' => $key,
                'text' => $requestData[$key],
            ]);

            return $carry;
        }, []);

        $media = ImageUpload::addTo(User::first(), $image, 'imagesMedia');

        $params[] = [
            'layer' => 'image',
            'image_url' => $media->getUrl('image'),
        ];

        return [
            'params' => $params,
            'media' => $media,
        ];
    }
}

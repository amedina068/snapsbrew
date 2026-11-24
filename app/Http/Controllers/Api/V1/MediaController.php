<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\User;
use App\Support\ImageUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaController extends Controller
{
    /**
     * The body is a flat Laravel paginator, as `ApiSpec` documents it.
     * `MediaResource::collection()` would move the page fields under `meta`,
     * so each item is resolved inside the paginator instead.
     */
    public function index(Request $request)
    {
        $images = Media::where([
            'model_type' => User::class,
            'model_id' => Auth::id(),
            'collection_name' => 'imagesMedia',
        ])->orderBy('created_at', 'DESC')->paginate(12);

        return response()->json(
            $images->through(fn (Media $media) => (new MediaResource($media))->resolve($request))
        );
    }

    /**
     * The body is the media item with no `data` wrapper, and the status is
     * 200. A resource response answers 201 for a new model and adds `data`.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', ...ImageUpload::rules()],
        ]);

        $media = ImageUpload::addTo(Auth::user(), $request->file('file'), 'imagesMedia');

        return response()->json((new MediaResource($media))->resolve($request));
    }
}

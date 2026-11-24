<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScreenshotResource;
use App\Http\Responses\ApiResponse;
use App\Models\Screenshot;
use App\Models\Template;
use App\Rules\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScreenshotController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'cursor' => ['string', 'sometimes'],
        ]);

        // The table has no `image_url` column. The URL comes from the media
        // library, as in the read, so `media` is eager loaded to keep the page
        // at two queries. The item names its id `uuid`, which clients read.
        $screenshots = Screenshot::query()
            ->where('company_id', Auth::user()->company_id)
            ->with('media')
            ->cursorPaginate();

        $items = array_map(fn (Screenshot $screenshot) => [
            'uuid' => $screenshot->uuid,
            'url' => $screenshot->url,
            'image_url' => $screenshot->imageUrl,
            'width' => $screenshot->width,
            'height' => $screenshot->height,
            'created_at' => $screenshot->created_at,
        ], $screenshots->items());

        return ApiResponse::page($items, [
            'previous_cursor' => optional($screenshots->previousCursor())->encode(),
            'next_cursor' => optional($screenshots->nextCursor())->encode(),
        ]);
    }

    /**
     * Read one screenshot.
     *
     * The body is `{"data": ...}`. The method returns the resource, and
     * Laravel calls `JsonResource::toResponse()`, which applies the wrapper.
     * `response()->json(new ScreenshotResource(...))` reads `jsonSerialize()`
     * instead, so it answered the fields flat and stripped the envelope
     * `ApiSpec` already documented.
     */
    public function show($screenshotUuid)
    {
        $screenshot = Screenshot::query()
            ->uuid($screenshotUuid)
            ->where('company_id', Auth::user()->company_id)
            ->firstOrFail();

        return new ScreenshotResource($screenshot);
    }

    /**
     * Capture a page and return the screenshot.
     *
     * The body is `{"data": ...}`, for the reason `show()` states.
     *
     * The status is pinned to 200. `ResourceResponse::calculateStatus()`
     * answers 201 for a POST whose model `wasRecentlyCreated`, so returning
     * the resource alone moved this endpoint from 200 to 201. `ApiSpec`
     * documents 200 and this change carries the envelope only, so the status
     * stays where it was.
     */
    public function store(Request $request)
    {
        $request->validate([
            'url' => ['required', 'url', new SafeUrl],
            // The browser opens a viewport of this size. Use the canvas bounds.
            'height' => ['integer', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'width' => ['integer', 'min:1', 'max:'.Template::MAX_DIMENSION],
        ]);

        $requestData = $request->only('url', 'height', 'width');

        if (! $request->filled('width')) {
            $requestData['width'] = 1330;
        }

        if (! $request->filled('height')) {
            $requestData['height'] = 780;
        }

        $requestData['user_id'] = Auth::id();

        $screenshot = Auth::user()->company->screenshots()->create($requestData);

        $screenshot->getImage();

        activity()
            ->performedOn($screenshot)
            ->causedBy(Auth::user())
            ->log('created');

        return (new ScreenshotResource($screenshot))->response()->setStatusCode(200);
    }
}

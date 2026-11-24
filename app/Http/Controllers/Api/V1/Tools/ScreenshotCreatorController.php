<?php

namespace App\Http\Controllers\Api\V1\Tools;

use App\Http\Controllers\Controller;
use App\Models\Screenshot;
use App\Models\User;
use App\Rules\SafeUrl;
use Illuminate\Http\Request;

class ScreenshotCreatorController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'url' => ['required', 'url', new SafeUrl],
            'full_page' => ['required', 'boolean'],
            'mobile' => ['required', 'boolean'],
        ]);

        $owner = User::find(config('services.free_tools.user_id'));

        if (! $owner) {
            return response()->json([
                'message' => 'The screenshot tool is not available. Set SNAPSBREW_FREE_TOOLS_USER_ID to an existing user.',
            ], 503);
        }

        $width = 1330;
        $height = 780;
        $user_id = $owner->id;
        $company_id = $owner->company_id;

        if ($request->mobile) {
            $width = 480;
            $height = 1080;
        }

        $screenshot = Screenshot::create([
            'url' => $request->url,
            'width' => $width,
            'height' => $height,
            'full_page' => $request->boolean('full_page'),
            'user_id' => $user_id,
            'company_id' => $company_id,
        ]);

        $image = $screenshot->getImage();

        return response()->json([
            'image_path' => $image,
        ]);
    }
}

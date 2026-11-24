<?php

namespace App\Http\Controllers;

use App\Models\Screenshot;
use App\Models\Template;
use App\Rules\SafeUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScreenshotController extends Controller
{
    public function index()
    {
        $screenshots = Screenshot::query()
            ->where('company_id', Auth::user()->company_id)
            ->latest('id')
            ->select('id', 'url', 'uuid')
            ->get();

        return view('screenshots.index', compact('screenshots'));
    }

    public function show($screenshotUuid)
    {
        $screenshot = Screenshot::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($screenshotUuid)
            ->firstOrFail();

        return view('screenshots.show', compact('screenshot'));
    }

    public function create()
    {
        return view('screenshots.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'url' => ['required', 'url', new SafeUrl],
            // The browser opens a viewport of this size. Use the canvas bounds.
            'height' => ['integer', 'min:1', 'max:'.Template::MAX_DIMENSION],
            'width' => ['integer', 'min:1', 'max:'.Template::MAX_DIMENSION],
        ]);

        $screenshot = Auth::user()->company->screenshots()->create($request->merge([
            'user_id' => Auth::id(),
        ])->only('url', 'height', 'width', 'user_id'));

        $image = $screenshot->getImage();

        activity()
            ->performedOn($screenshot)
            ->causedBy(Auth::user())
            ->log('created');

        return redirect("screenshots/{$screenshot->uuid}");
    }
}

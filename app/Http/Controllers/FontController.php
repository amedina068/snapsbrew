<?php

namespace App\Http\Controllers;

use App\Models\Font;
use App\Models\Template;
use App\Rules\ValidFontFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FontController extends Controller
{
    public function index()
    {
        $fonts = Font::where('company_id', Auth::user()->company_id)
            ->latest()
            ->get(['uuid', 'name', 'family', 'created_at']);

        // Surface usage inline so a delete is an informed choice, never a
        // silent fallback under a live template (T-605).
        $fonts->each(fn ($font) => $font->setAttribute(
            'used_by',
            $this->templatesUsingFamily($font->family, Auth::user()->company_id)
        ));

        return view('fonts.index', ['fonts' => $fonts]);
    }

    /** JSON list for the editor font picker + live @font-face preview. */
    public function list()
    {
        $fonts = Font::where('company_id', Auth::user()->company_id)
            ->latest()
            ->get()
            ->map(fn ($font) => [
                'family' => $font->family,
                'name' => $font->name,
                'url' => $font->url,
            ]);

        return response()->json(['data' => $fonts]);
    }

    public function store(Request $request)
    {
        $companyId = Auth::user()->company_id;

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // The family lands in CSS at render time; allowlist it to a safe set
            // (S2/S4) and keep it unique per company so @font-face is unambiguous.
            'family' => [
                'required', 'string', 'regex:'.Font::FAMILY_PATTERN,
                Rule::unique('fonts')->where('company_id', $companyId),
            ],
            'file' => ['required', 'file', 'max:'.(Font::MAX_FILE_SIZE / 1024), new ValidFontFile],
        ]);

        if (Font::where('company_id', $companyId)->count() >= Font::MAX_FONTS_PER_COMPANY) {
            return back()->withErrors([
                'file' => 'You have reached your font storage limit. Delete an unused font to upload a new one.',
            ]);
        }

        $font = Font::create([
            'company_id' => $companyId,
            'user_id' => Auth::id(),
            'name' => $request->name,
            'family' => $request->family,
        ]);

        // Store under a UUID key, never the user-supplied filename.
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        $font->addMedia($request->file('file'))
            ->usingFileName(Str::uuid().'.'.$extension)
            ->toMediaCollection('file');

        return back()->with('status', 'Font uploaded.');
    }

    public function destroy(Request $request, string $uuid)
    {
        $font = Font::where('uuid', $uuid)->firstOrFail();

        $this->authorize('delete', $font);

        // Never silently swap a font out from under a template (T-605): if the
        // family is still in use, block and name the templates unless the user
        // explicitly confirms the fallback with `force`.
        $usedBy = $this->templatesUsingFamily($font->family, $font->company_id);

        if ($usedBy->isNotEmpty() && ! $request->boolean('force')) {
            return back()->withErrors([
                'font' => 'This font is still used by: '.$usedBy->pluck('title')->implode(', ')
                    .'. Those templates will fall back to a default font. Delete anyway to confirm.',
            ])->with('force_delete_uuid', $font->uuid);
        }

        $font->delete();

        return back()->with('status', 'Font deleted.');
    }

    /**
     * Templates in the company whose layers reference the given font family.
     * The family lands in layers as a `fontFamily` design-time property value.
     */
    private function templatesUsingFamily(string $family, int $companyId)
    {
        return Template::where('company_id', $companyId)
            ->get(['id', 'uuid', 'title', 'layers'])
            ->filter(function ($template) use ($family) {
                foreach (json_decode($template->layers, true) ?: [] as $layer) {
                    foreach ($layer['properties'] ?? [] as $property) {
                        if (($property['name'] ?? null) === 'fontFamily'
                            && ($property['value'] ?? null) === $family) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->values();
    }
}

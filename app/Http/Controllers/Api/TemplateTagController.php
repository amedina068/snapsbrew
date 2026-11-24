<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TemplateTagController extends Controller
{
    public function store(Request $request, $templateUuid)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($templateUuid)
            ->firstOrFail();

        $validated = $request->validate([
            'tag_ids' => ['array'],
            'tag_ids.*' => [
                'integer',
                Rule::exists('tags', 'id')->where('company_id', Auth::user()->company_id),
            ],
        ]);

        $uniqueTagIds = collect($validated['tag_ids'] ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $template->tags()->sync($uniqueTagIds);

        $template->load('tags');

        return response()->json([
            'message' => 'Tags updated successfully.',
            'tags' => $template->tags->map(fn ($tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
            ]),
        ]);
    }
}

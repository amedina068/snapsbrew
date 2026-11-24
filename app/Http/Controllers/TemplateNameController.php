<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TemplateNameController extends Controller
{
    public function update(Request $request, $templateUuid)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($templateUuid)
            ->firstOrFail();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $template->update(['title' => $validated['title']]);

        return response()->json([
            'message' => 'Template renamed successfully.',
        ]);
    }
}

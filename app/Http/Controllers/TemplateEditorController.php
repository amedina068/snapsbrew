<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Support\TemplateSizes;
use Illuminate\Support\Facades\Auth;

class TemplateEditorController extends Controller
{
    public function edit($templateUuid)
    {
        $template = Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($templateUuid)
            ->firstOrFail();

        return view('templates.editor', [
            'template' => $template,
            'templateSizes' => TemplateSizes::grouped(),
        ]);
    }
}

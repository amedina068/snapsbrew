<?php

namespace App\Http\Controllers;

use App\Models\SnapImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SnapImportStatusController extends Controller
{
    public function __invoke(Request $request, $importUuid)
    {
        $import = SnapImport::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($importUuid)
            ->firstOrFail();

        return response()->json([
            'import' => $import->progressSnapshot(),
        ]);
    }
}

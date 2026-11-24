<?php

namespace App\Http\Controllers;

use App\Models\SnapImport;
use App\Services\SnapImportFinalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SnapImportCancelController extends Controller
{
    public function __invoke(Request $request, $importUuid)
    {
        $import = SnapImport::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($importUuid)
            ->firstOrFail();

        // A batch that already finished answers with its snapshot and changes
        // nothing. The API surface calls the same method.
        SnapImportFinalizer::cancel($import);

        return response()->json([
            'import' => $import->fresh()->progressSnapshot(),
        ]);
    }
}

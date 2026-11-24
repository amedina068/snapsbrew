<?php

namespace App\Http\Controllers;

use App\Models\Snap;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $company = Auth::user()->company;

        $hasSnaps = $company->snaps()->exists() ?? false;
        $hasScreenshots = $company->screenshots()->exists() ?? false;
        $hasTemplates = $company->templates()->exists() ?? false;
        $hasActivity = $hasTemplates || $hasSnaps || $hasScreenshots;

        $hasCreatedSnap = $company->first_snap_at !== null;

        $brewingShowcase = ! $hasCreatedSnap && $company->snaps()
            ->where('metadata->onboarding', 'showcase')
            ->whereIn('status', [Snap::STATUS_PENDING, Snap::STATUS_PROCESSING])
            ->exists();

        return view('dashboard', compact(
            'hasActivity',
            'hasSnaps',
            'hasScreenshots',
            'hasCreatedSnap',
            'brewingShowcase',
        ));
    }
}

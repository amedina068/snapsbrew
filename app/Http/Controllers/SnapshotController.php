<?php

namespace App\Http\Controllers;

use App\Models\ApiLog;
use App\Models\Snap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SnapshotController extends Controller
{
    public function index()
    {
        $snapshots = Snap::query()
            ->where('user_id', Auth::id())
            ->latest('created_at')
            ->paginate(15);

        return view('snapshots.index', compact('snapshots'));
    }

    public function show($snapshotUuid)
    {
        $snapshot = Snap::query()
            ->where('user_id', Auth::id())
            ->with([
                'template',
                'import.template',
                'form.formable',
            ])
            ->uuid($snapshotUuid)
            ->firstOrFail();

        $apiLog = ApiLog::query()
            ->where('user_id', Auth::id())
            ->whereNotNull('response')
            ->where('response', 'like', '%'.$snapshot->uuid.'%')
            ->latest('id')
            ->first();

        $template = $snapshot->template;
        $templateLink = $template ? url("/templates/{$template->uuid}") : null;

        $import = $snapshot->import;
        $importLink = null;

        if ($import) {
            $importTemplateUuid = $import->template?->uuid ?? $template?->uuid;

            if ($importTemplateUuid) {
                $importLink = url("/templates/{$importTemplateUuid}/imports/{$import->uuid}");
            }
        }

        $form = $snapshot->form;
        $formLink = null;

        if ($form && $form->formable) {
            if ($form->isForTemplate()) {
                $formLink = url("/templates/{$form->formable->uuid}/forms/{$form->uuid}");
            } elseif ($form->isForCollection()) {
                $formLink = url("/collections/{$form->formable->uuid}/forms/{$form->uuid}");
            }
        }

        $apiLogLink = $apiLog ? url("/logs/{$apiLog->uuid}") : null;

        $breadcrumbs = [
            ['label' => 'Snaps', 'href' => url('/snapshots')],
            ['label' => $snapshot->identifier ?? 'Snap '.$snapshot->uuid, 'current' => true],
        ];

        return view('snapshots.show', compact(
            'snapshot',
            'apiLog',
            'template',
            'import',
            'form',
            'templateLink',
            'importLink',
            'formLink',
            'apiLogLink',
            'breadcrumbs'
        ));
    }

    public function destroy($snapshotUuid)
    {
        $snapshot = Snap::where('user_id', Auth::id())->uuid($snapshotUuid)->firstOrFail();

        $snapshot->delete();

        return back()->with('success', 'The snap is deleted.');
    }

    public function bulkDestroy(Request $request)
    {
        $request->validate([
            'snaps_ids' => ['required', 'array'],
            'snaps_ids.*' => ['string'],
        ], [
            'snaps_ids.required' => 'Select at least one snap to delete.',
        ]);

        Snap::query()
            ->where('user_id', Auth::id())
            ->whereIn('uuid', $request->snaps_ids)
            ->cursor()
            ->each
            ->delete();

        return back()->with('success', 'The selected snaps are deleted.');
    }
}

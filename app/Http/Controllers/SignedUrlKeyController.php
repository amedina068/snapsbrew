<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class SignedUrlKeyController extends Controller
{
    public function store(): RedirectResponse
    {
        Auth::user()->createSignedUrlToken();

        return back()->with([
            'status' => 'Signed URL key generated successfully.',
            'open_tab' => 'developers',
        ]);
    }

    public function destroy($apiKeyId): RedirectResponse
    {
        $apiKey = ApiKey::query()
            ->with(['accessToken'])
            // Only the owner of a key can revoke it. The developers page lists
            // only the keys of the signed-in user.
            ->where('user_id', Auth::id())
            ->where('id', $apiKeyId)
            ->firstOrFail();

        if ($apiKey->accessToken) {
            $apiKey->accessToken->delete();
        }

        $apiKey->update([
            'invalidated_at' => now(),
        ]);

        return back()->with([
            'status' => 'Signed URL key revoked.',
            'open_tab' => 'developers',
        ]);
    }
}

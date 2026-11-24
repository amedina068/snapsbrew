<?php

namespace App\Http\Controllers;

use App\Services\OAuthConnections;
use Illuminate\Support\Facades\Auth;

class OAuthConnectionController extends Controller
{
    public function destroy(string $clientId, OAuthConnections $connections)
    {
        abort_unless($connections->revoke(Auth::user(), $clientId), 404);

        return back()->with([
            'status' => 'OAuth connection revoked.',
            'open_tab' => 'developers',
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiTokenController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['string', 'required'],
        ]);

        $token = Auth::user()->createToken($request->name, ['api_requests']);

        return back()->with([
            'token' => $token->plainTextToken,
            'open_tab' => 'developers',
        ]);
    }

    public function destroy($tokenId)
    {
        // Delete through the model, so the `deleted` event clears the signed
        // URL key cache. A query delete fires no event.
        Auth::user()->tokens()->where('id', $tokenId)->get()->each->delete();

        return back()->with([
            'status' => 'API token revoked.',
            'open_tab' => 'developers',
        ]);
    }
}

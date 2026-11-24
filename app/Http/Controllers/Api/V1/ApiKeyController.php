<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiKeyController extends Controller
{
    public function index()
    {
        $tokens = Auth::user()->tokens;

        return response()->json([
            'data' => $tokens,
        ]);
    }

    public function store(Request $request)
    {
        $token = Auth::user()->createToken($request->name);

        return response()->json([
            'data' => $token,
        ]);
    }

    public function destroy($tokenId)
    {
        // Delete through the model, so the `deleted` event clears the signed
        // URL key cache. A query delete fires no event.
        Auth::user()->tokens()->where('id', $tokenId)->get()->each->delete();

        return response()->json();
    }
}

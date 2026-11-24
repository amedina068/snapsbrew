<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Return the account the token belongs to.
     *
     * The body is `{"data": ...}`. The method returns the resource, and
     * Laravel calls `JsonResource::toResponse()`, which applies the wrapper.
     * `response()->json(new UserResource(...))` reads `jsonSerialize()`
     * instead, so it answered the three fields flat and stripped the
     * envelope `ApiSpec` already documented.
     */
    public function index()
    {
        return new UserResource(Auth::user());
    }
}

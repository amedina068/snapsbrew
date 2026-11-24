<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogApiRequestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        $company = $user->company()->first();

        // Hand the loaded company to the request that follows.
        //
        // This middleware reads the company on every logged route. Without
        // this line a controller that reads `$user->company` runs a second
        // query for the row this line already holds. `GET /v1/auth` did exactly
        // that once `UserResource` started returning the company name. A null
        // company caches as null, which answers a later read with no query.
        $user->setRelation('company', $company);

        $accessToken = $user->currentAccessToken();

        $apiLog = ApiLog::create([
            'request' => json_encode($request->all()),
            'user_id' => $user->id,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip_address' => $request->ip(),
            'personal_access_token_id' => optional($accessToken)->id,
            'type' => 'api_token',
        ]);

        $request->merge([
            'api_log_uuid' => $apiLog->uuid,
        ]);

        return $next($request);
    }
}

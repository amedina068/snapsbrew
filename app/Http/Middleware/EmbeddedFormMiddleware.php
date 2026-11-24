<?php

namespace App\Http\Middleware;

use App\Models\ApiLog;
use App\Models\Form;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

class EmbeddedFormMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $form = Form::query()
            ->uuid($request->route()->parameter('formUuid'))
            ->firstOrFail();

        $user = User::query()->findOrFail($form->user_id);

        // The form renders on the account of its owner. A deactivated owner
        // gets no render and no usage row.
        abort_if($user->isDeactivated(), 404);

        $apiLog = ApiLog::create([
            'request' => json_encode($request->all()),
            'user_id' => $user->id,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'ip_address' => $request->ip(),
            'type' => 'embedded_form',
        ]);

        $request->merge([
            'api_log_uuid' => $apiLog->uuid,
        ]);

        return $next($request);
    }
}

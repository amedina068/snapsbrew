<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;

/**
 * GET /up. The container health check reads it. It answers 200 when the app
 * boots and every DiagnosingHealth listener passes, and 500 when one fails.
 * The route has no middleware, so it starts no session.
 */
class HealthCheckController extends Controller
{
    public function __invoke(): Response
    {
        try {
            Event::dispatch(new DiagnosingHealth);
        } catch (\Throwable $exception) {
            report($exception);

            return response('Application down', 500);
        }

        return response('Application up', 200);
    }
}

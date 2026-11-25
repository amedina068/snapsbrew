<?php

namespace Tests\Support;

use App\Providers\HorizonServiceProvider;
use Laravel\Horizon\HorizonServiceProvider as HorizonPackageServiceProvider;

/**
 * config/app.php loads Horizon only with QUEUE_CONNECTION=redis, and the
 * suite runs the sync queue. A test of the Horizon gate or the Horizon page
 * registers the two providers itself, as a Redis install does at boot.
 */
trait LoadsHorizon
{
    protected function setUpLoadsHorizon(): void
    {
        $this->app->register(HorizonPackageServiceProvider::class);
        $this->app->register(HorizonServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}

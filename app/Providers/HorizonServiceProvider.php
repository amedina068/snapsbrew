<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Only the owner opens Horizon, in every environment.
     *
     * The parent method also lets every request in through the `local`
     * environment. A self-hosted instance that keeps APP_ENV=local then shows
     * its queues and job payloads to anyone.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request) => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Register the Horizon gate. Only the owner of the instance passes it.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return (bool) optional($user)->isOwner();
        });
    }
}

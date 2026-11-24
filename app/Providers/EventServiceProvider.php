<?php

namespace App\Providers;

use App\Listeners\RecordUserEvent;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [];

    /**
     * Subscriber classes resolved from the container.
     *
     * @var array
     */
    protected $subscribe = [
        RecordUserEvent::class,
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}

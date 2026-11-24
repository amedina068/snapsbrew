<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\StarterTemplateInstaller;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Adds the starter templates after the setup page creates the owner.
 *
 * The installer renders six preview images, so the setup request does not
 * wait for it. A retry is safe, because the installer skips each starter
 * template that the company already has.
 */
class InstallStarterTemplates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public User $user)
    {
        $this->onQueue(Queues::DEFAULT);
    }

    public function handle(StarterTemplateInstaller $installer): void
    {
        $installer->install($this->user);
    }
}

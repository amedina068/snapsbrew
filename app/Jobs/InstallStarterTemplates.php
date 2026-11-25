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
 * Adds the starter templates to the company of a user on the queue.
 *
 * The setup page does not use this job. It adds the templates in the request
 * and queues one GenerateTemplatePreview job for each template. A caller that
 * must not wait for the six preview images, for example a command or an admin
 * tool, queues this job. A retry is safe, because the installer skips each
 * starter template that the company already has.
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

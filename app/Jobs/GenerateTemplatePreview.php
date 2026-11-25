<?php

namespace App\Jobs;

use App\Models\Template;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Renders the preview image of a template off the request path.
 *
 * The setup page adds the starter templates in the request, so the owner sees
 * them on the first dashboard load. Their preview images come later from this
 * job. The job does nothing when the template is gone or already has a preview.
 */
class GenerateTemplatePreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Template $template)
    {
        $this->onQueue(Queues::DEFAULT);
    }

    public function handle(): void
    {
        $template = $this->template->fresh();

        if (! $template || $template->hasMedia('preview')) {
            return;
        }

        $template->generatePreviewImage();
    }
}

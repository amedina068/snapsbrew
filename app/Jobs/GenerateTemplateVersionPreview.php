<?php

namespace App\Jobs;

use App\Models\TemplateVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Renders a template version's preview image off the request path.
 *
 * Browsershot is slow, so publish/revert dispatch this instead of rendering
 * inline. Idempotent: a no-op if the version is gone or already has its image,
 * so a retry or duplicate dispatch never double-renders.
 */
class GenerateTemplateVersionPreview implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private TemplateVersion $version) {}

    public function handle(): void
    {
        $version = $this->version->fresh();

        if (! $version || $version->hasMedia('preview')) {
            return;
        }

        $version->generatePreview();
    }
}

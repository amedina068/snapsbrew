<?php

namespace App\Jobs;

use App\Models\Snap;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RemoveFreeToolSnap implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        public Snap $snap,
        public $media = null
    ) {}

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $this->snap->delete();
        $this->media->delete();
    }
}

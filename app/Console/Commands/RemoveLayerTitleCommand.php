<?php

namespace App\Console\Commands;

use App\Models\Template;
use Illuminate\Console\Command;

class RemoveLayerTitleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sb:remove-template-layers-title';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove Template layer name';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Template::query()->cursor()->each(function ($template) {
            $layers = json_decode($template->layers, true);

            $layers = collect($layers)->map(function ($layer) {
                unset($layer['title']);

                return $layer;
            })->toJson();

            $template->update(['layers' => $layers]);
        });

        return Command::SUCCESS;
    }
}

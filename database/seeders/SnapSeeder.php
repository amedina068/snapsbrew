<?php

namespace Database\Seeders;

use App\Models\Snap;
use App\Models\Template;
use Illuminate\Database\Seeder;

class SnapSeeder extends Seeder
{
    public function run()
    {
        Template::whereNotNull('user_id')->get()->each(function (Template $template) {
            Snap::factory()
                ->count(rand(2, 5))
                ->create([
                    'template_id' => $template->id,
                    'company_id' => $template->company_id,
                    'status' => Snap::STATUS_COMPLETED,
                    'completed_at' => now()->subDays(rand(0, 30)),
                ]);
        });
    }
}

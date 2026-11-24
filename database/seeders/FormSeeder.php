<?php

namespace Database\Seeders;

use App\Models\Form;
use App\Models\Template;
use Illuminate\Database\Seeder;

class FormSeeder extends Seeder
{
    public function run()
    {
        Template::whereNotNull('user_id')
            ->take(5)
            ->get()
            ->each(function (Template $template) {
                Form::factory()->create([
                    'user_id' => $template->user_id,
                    'company_id' => $template->company_id,
                    'formable_id' => $template->id,
                    'formable_type' => Template::class,
                ]);
            });
    }
}

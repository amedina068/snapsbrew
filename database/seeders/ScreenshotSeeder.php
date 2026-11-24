<?php

namespace Database\Seeders;

use App\Models\Screenshot;
use App\Models\User;
use Illuminate\Database\Seeder;

class ScreenshotSeeder extends Seeder
{
    public function run()
    {
        User::all()->take(5)->each(function (User $user) {
            Screenshot::factory()
                ->count(rand(1, 3))
                ->create([
                    'user_id' => $user->id,
                    'company_id' => $user->company_id,
                ]);
        });
    }
}

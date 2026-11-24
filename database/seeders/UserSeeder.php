<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run()
    {
        Company::all()->each(function (Company $company) {
            // Create 1-3 users per company
            User::factory()
                ->count(rand(1, 3))
                ->create([
                    'company_id' => $company->id,
                ]);
        });
    }
}

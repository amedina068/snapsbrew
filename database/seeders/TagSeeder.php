<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run()
    {
        Company::all()->each(function (Company $company) {
            Tag::factory()
                ->count(rand(3, 6))
                ->create([
                    'company_id' => $company->id,
                ]);
        });
    }
}

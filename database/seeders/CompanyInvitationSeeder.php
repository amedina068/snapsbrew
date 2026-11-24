<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CompanyInvitation;
use Illuminate\Database\Seeder;

class CompanyInvitationSeeder extends Seeder
{
    public function run()
    {
        Company::all()->take(3)->each(function (Company $company) {
            $inviter = $company->users()->first();

            if (! $inviter) {
                return;
            }

            CompanyInvitation::factory()
                ->count(2)
                ->create([
                    'company_id' => $company->id,
                    'invited_by' => $inviter->id,
                ]);
        });
    }
}

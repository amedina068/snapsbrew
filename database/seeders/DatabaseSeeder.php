<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // The demo users get the factory password, and the repository is
        // public. `--force` skips the production prompt of `db:seed`, so the
        // seeder checks the environment itself.
        if (app()->isProduction()) {
            throw new RuntimeException('The demo seeder creates users with a public password. It does not run in production.');
        }

        // The account of the developer. It is an owner, so it can open /admin
        // and /horizon. The factory sets the password to "password".
        $company = Company::factory()->create([
            'name' => 'Snapsbrew Admin',
        ]);

        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'company_id' => $company->id,
            'is_owner' => true,
        ]);

        // Create demo companies with users
        $this->call([
            CompanySeeder::class,
            UserSeeder::class,
            TemplateSeeder::class,
            TagSeeder::class,
            SnapSeeder::class,
            ScreenshotSeeder::class,
            FormSeeder::class,
            CompanyInvitationSeeder::class,
        ]);

        // Create some templates for the admin company
        Template::factory()->count(3)->create([
            'user_id' => $admin->id,
            'company_id' => $company->id,
        ]);

        $this->command?->info('Sign in as admin@example.com with the password "password". This account is an owner.');
    }
}

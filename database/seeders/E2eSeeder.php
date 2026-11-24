<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The known state of the Playwright end-to-end suite (tests/e2e).
 *
 * The user gets the factory password "password", and the repository is
 * public. The seeder runs only in the local, testing and e2e environments.
 */
class E2eSeeder extends Seeder
{
    public const USER_EMAIL = 'e2e@example.test';

    public const STARTER_TEMPLATE_TITLE = 'E2E Starter';

    public const OWN_TEMPLATE_TITLE = 'E2E Seeded Template';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'e2e'])) {
            throw new RuntimeException('The E2E seeder creates a user with a public password. It runs only in the local, testing and e2e environments.');
        }

        $company = Company::factory()->create([
            'name' => 'E2E Diner',
        ]);

        $user = User::factory()->create([
            'name' => 'E2E Tester',
            'email' => self::USER_EMAIL,
            'company_id' => $company->id,
        ]);

        // A starting template has no user. The "New template" page lists it.
        Template::factory()->create([
            'user_id' => null,
            'company_id' => null,
            'title' => self::STARTER_TEMPLATE_TITLE,
            'width' => 1200,
            'height' => 630,
        ]);

        Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'title' => self::OWN_TEMPLATE_TITLE,
            'width' => 1080,
            'height' => 1080,
        ]);
    }
}

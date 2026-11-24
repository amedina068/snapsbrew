<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupUrlCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prints_the_setup_url_while_setup_is_needed()
    {
        $token = substr(hash_hmac('sha256', 'snapsbrew-setup', config('app.key')), 0, 32);

        $this->artisan('snapsbrew:setup-url')
            ->expectsOutput(url('/setup?token='.$token))
            ->assertSuccessful();
    }

    public function test_it_prints_no_url_after_an_account_exists()
    {
        User::factory()->create();

        $this->artisan('snapsbrew:setup-url')
            ->expectsOutputToContain('An account already exists')
            ->doesntExpectOutputToContain('/setup?token=')
            ->assertSuccessful();
    }
}

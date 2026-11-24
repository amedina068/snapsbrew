<?php

namespace Tests\Feature;

use App\Models\CompanyInvitation;
use App\Models\User;
use App\Support\InstanceSettings;
use App\Support\Registration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The owner sets the registration mode in the admin area. The database value
 * wins. REGISTRATION_ENABLED gives the mode only when the database has none.
 */
class RegistrationModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->completeInstanceSetup();
    }

    private function signUp(string $email, array $extra = [])
    {
        return $this->post('/register', [
            'name' => 'Jane',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ] + $extra);
    }

    public function test_the_env_switch_is_the_default_when_the_database_has_no_value()
    {
        config(['snapsbrew.registration_enabled' => false]);
        $this->assertSame(Registration::INVITE_ONLY, Registration::mode());
        $this->assertFalse(Registration::isOpen());

        config(['snapsbrew.registration_enabled' => true]);
        $this->assertSame(Registration::OPEN, Registration::mode());
        $this->assertTrue(Registration::isOpen());
    }

    public function test_the_database_value_wins_over_the_env_switch()
    {
        config(['snapsbrew.registration_enabled' => true]);
        Registration::setMode(Registration::INVITE_ONLY);
        $this->assertFalse(Registration::isOpen());

        config(['snapsbrew.registration_enabled' => false]);
        Registration::setMode(Registration::OPEN);
        $this->assertTrue(Registration::isOpen());
    }

    public function test_an_unknown_value_in_the_database_falls_back_to_the_env_switch()
    {
        config(['snapsbrew.registration_enabled' => false]);
        DB::table('instance_settings')->insert(['key' => Registration::SETTING, 'value' => 'garbage']);
        app(InstanceSettings::class)->flush();

        $this->assertSame(Registration::INVITE_ONLY, Registration::mode());
    }

    public function test_the_read_is_cached_and_a_write_refreshes_it()
    {
        config(['snapsbrew.registration_enabled' => false]);
        Registration::setMode(Registration::OPEN);
        $this->assertTrue(Registration::isOpen());

        DB::enableQueryLog();
        Registration::isOpen();
        Registration::isOpen();
        $this->assertCount(0, DB::getQueryLog());

        Registration::setMode(Registration::INVITE_ONLY);
        $this->assertFalse(Registration::isOpen());
    }

    public function test_open_mode_allows_a_sign_up_even_when_the_env_switch_is_off()
    {
        config(['snapsbrew.registration_enabled' => false]);
        Registration::setMode(Registration::OPEN);

        $this->get('/register')->assertOk();
        $this->signUp('jane@example.com')->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'jane@example.com']);
    }

    public function test_invite_only_mode_refuses_a_sign_up_even_when_the_env_switch_is_on()
    {
        config(['snapsbrew.registration_enabled' => true]);
        Registration::setMode(Registration::INVITE_ONLY);

        $this->get('/register')->assertNotFound();
        $this->signUp('mallory@example.com')->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'mallory@example.com']);
    }

    public function test_invite_only_mode_still_accepts_a_pending_invitation()
    {
        config(['snapsbrew.registration_enabled' => true]);
        Registration::setMode(Registration::INVITE_ONLY);
        $invitation = CompanyInvitation::factory()->create(['email' => 'teammate@example.com']);

        $this->get('/register?invitation='.$invitation->token)->assertOk();
        $this->signUp('teammate@example.com', ['invitation_token' => $invitation->token])->assertRedirect();

        $this->assertSame($invitation->company_id, User::where('email', 'teammate@example.com')->value('company_id'));
    }

    public function test_the_sign_up_links_follow_the_mode()
    {
        config(['snapsbrew.registration_enabled' => false]);

        Registration::setMode(Registration::INVITE_ONLY);
        $this->get('/login')->assertDontSee('href="/register"', false);
        $this->get('/forgot-password')->assertDontSee('href="/register"', false);

        Registration::setMode(Registration::OPEN);
        $this->get('/login')->assertSee('href="/register"', false);
        $this->get('/forgot-password')->assertSee('href="/register"', false);
    }
}

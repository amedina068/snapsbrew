<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\Template;
use App\Models\User;
use App\Support\StarterTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeRenderer;
use Tests\Support\WithPassportKeys;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase, WithPassportKeys;

    private const STARTER_QUESTION = 'Add the 6 starter templates to the owner account?';

    private function starterTemplateCount(): int
    {
        return Template::query()->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')->count();
    }

    public function test_install_creates_the_owner_and_the_company()
    {
        $this->artisan('snapsbrew:install', [
            '--name' => 'Olive Owner',
            '--email' => 'owner@example.com',
            '--password' => 'a-long-password-1',
            '--company' => 'Olive Studio',
        ])->expectsConfirmation(self::STARTER_QUESTION, 'no')->assertSuccessful();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();

        $this->assertTrue($owner->isOwner());
        $this->assertSame(User::ROLE_CUSTOMER, $owner->role);
        $this->assertSame('Olive Studio', $owner->company->name);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('a-long-password-1', $owner->password));
        $this->assertDatabaseHas('api_keys', ['user_id' => $owner->id]);
    }

    public function test_install_works_while_registration_is_closed()
    {
        config(['snapsbrew.registration_enabled' => false]);

        $this->artisan('snapsbrew:install', [
            '--name' => 'Olive Owner',
            '--email' => 'owner@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsConfirmation(self::STARTER_QUESTION, 'no')->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'is_owner' => true]);
    }

    public function test_install_adds_the_starter_templates_to_the_owner_when_the_answer_is_yes()
    {
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        $this->artisan('snapsbrew:install', [
            '--name' => 'Olive Owner',
            '--email' => 'owner@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsConfirmation(self::STARTER_QUESTION, 'yes')
            ->expectsOutputToContain('Added 6 starter templates')
            ->assertSuccessful();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame(6, Template::query()->where('company_id', $owner->company_id)->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')->count());
    }

    public function test_install_tells_how_to_add_the_starter_templates_later_when_the_answer_is_no()
    {
        $this->artisan('snapsbrew:install', [
            '--name' => 'Olive Owner',
            '--email' => 'owner@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsConfirmation(self::STARTER_QUESTION, 'no')
            ->expectsOutputToContain('snapsbrew:starter-templates')
            ->assertSuccessful();

        $this->assertSame(0, $this->starterTemplateCount());
    }

    public function test_the_no_starter_templates_option_asks_nothing_and_adds_nothing()
    {
        $this->artisan('snapsbrew:install', [
            '--name' => 'Olive Owner',
            '--email' => 'owner@example.com',
            '--password' => 'a-long-password-1',
            '--no-starter-templates' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'is_owner' => true]);
        $this->assertSame(0, $this->starterTemplateCount());
    }

    public function test_a_second_install_creates_no_second_owner()
    {
        User::factory()->create(['is_owner' => true, 'email' => 'first@example.com']);

        $this->artisan('snapsbrew:install', [
            '--name' => 'Second',
            '--email' => 'second@example.com',
            '--password' => 'a-long-password-1',
        ])->expectsOutputToContain('An owner already exists')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_install_refuses_an_invalid_email_and_creates_nothing()
    {
        $this->artisan('snapsbrew:install', [
            '--name' => 'Olive Owner',
            '--email' => 'not-an-email',
            '--password' => 'a-long-password-1',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_prepare_creates_no_account_and_asks_nothing()
    {
        $this->artisan('snapsbrew:install', ['--prepare' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_install_promotes_an_existing_user_with_the_owner_flag()
    {
        User::factory()->create(['email' => 'already@example.com']);

        $this->artisan('snapsbrew:install', ['--promote' => 'already@example.com'])->assertSuccessful();

        $this->assertTrue(User::where('email', 'already@example.com')->first()->isOwner());
    }

    public function test_install_demotes_an_owner_when_another_owner_remains()
    {
        User::factory()->create(['email' => 'first@example.com', 'is_owner' => true]);
        User::factory()->create(['email' => 'second@example.com', 'is_owner' => true]);

        $this->artisan('snapsbrew:install', ['--demote' => 'second@example.com'])
            ->expectsOutputToContain('second@example.com is no longer an owner.')
            ->assertSuccessful();

        $this->assertFalse(User::where('email', 'second@example.com')->first()->isOwner());
        $this->assertTrue(User::where('email', 'first@example.com')->first()->isOwner());
    }

    public function test_install_refuses_to_demote_the_last_owner()
    {
        User::factory()->create(['email' => 'only@example.com', 'is_owner' => true]);

        $this->artisan('snapsbrew:install', ['--demote' => 'only@example.com'])
            ->expectsOutputToContain('only@example.com is the last owner. Promote another user first.')
            ->assertFailed();

        $this->assertTrue(User::where('email', 'only@example.com')->first()->isOwner());
    }

    public function test_install_refuses_to_demote_a_user_who_is_not_an_owner()
    {
        User::factory()->create(['email' => 'owner@example.com', 'is_owner' => true]);
        User::factory()->create(['email' => 'member@example.com']);

        $this->artisan('snapsbrew:install', ['--demote' => 'member@example.com'])
            ->expectsOutputToContain('member@example.com is not an owner.')
            ->assertFailed();
    }

    public function test_install_refuses_to_demote_an_unknown_email()
    {
        $this->artisan('snapsbrew:install', ['--demote' => 'nobody@example.com'])
            ->expectsOutputToContain('No user has the email nobody@example.com.')
            ->assertFailed();
    }
}

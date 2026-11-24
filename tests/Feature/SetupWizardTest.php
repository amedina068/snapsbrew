<?php

namespace Tests\Feature;

use App\Actions\Instance\CreateInstanceOwner;
use App\Contracts\SnapRenderer;
use App\Jobs\InstallStarterTemplates;
use App\Models\Template;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Support\Queues;
use App\Support\StarterTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private function token(): string
    {
        return substr(hash_hmac('sha256', 'snapsbrew-setup', config('app.key')), 0, 32);
    }

    /**
     * @return array<string, string>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'token' => $this->token(),
            'name' => 'Olive Owner',
            'email' => 'owner@example.com',
            'password' => 'a-long-password-1',
            'password_confirmation' => 'a-long-password-1',
            'company' => 'Olive Studio',
        ], $overrides);
    }

    public function test_the_setup_page_shows_the_form_with_the_token_from_the_url()
    {
        $this->get('/setup?token=abc123')
            ->assertOk()
            ->assertSee('value="abc123"', false)
            ->assertSee('snapsbrew:setup-url');
    }

    public function test_the_setup_page_offers_the_starter_templates_checked_by_default()
    {
        $this->get('/setup')
            ->assertOk()
            ->assertSee('Add the starter templates')
            ->assertSee('name="starter_templates" value="0"', false)
            ->assertSeeInOrder(['id="starter_templates"', 'value="1"', 'checked'], false);
    }

    public function test_the_checkbox_queues_the_starter_templates_for_the_new_owner()
    {
        Queue::fake();

        $this->post('/setup', $this->form(['starter_templates' => '1']))->assertRedirect();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        Queue::assertPushedOn(Queues::DEFAULT, InstallStarterTemplates::class, fn (InstallStarterTemplates $job) => $job->user->is($owner));
    }

    public function test_an_unchecked_box_queues_no_starter_templates()
    {
        Queue::fake();

        $this->post('/setup', $this->form(['starter_templates' => '0']))->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com']);
        Queue::assertNotPushed(InstallStarterTemplates::class);
    }

    public function test_a_failed_setup_queues_no_starter_templates()
    {
        Queue::fake();

        $this->post('/setup', $this->form(['token' => 'wrong-token', 'starter_templates' => '1']))
            ->assertSessionHasErrors('token');

        Queue::assertNotPushed(InstallStarterTemplates::class);
    }

    public function test_the_queued_job_adds_the_six_starter_templates_to_the_company_of_the_owner()
    {
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        $this->post('/setup', $this->form(['starter_templates' => '1']))->assertRedirect();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $templates = Template::query()->where('company_id', $owner->company_id)->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')->get();

        $this->assertCount(6, $templates);
        $this->assertTrue($templates->every(fn (Template $template) => $template->user_id === $owner->id && $template->published_version_id !== null));
    }

    public function test_a_wrong_token_creates_no_account()
    {
        $this->post('/setup', $this->form(['token' => 'wrong-token']))
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_a_missing_token_creates_no_account()
    {
        $this->post('/setup', $this->form(['token' => '']))
            ->assertSessionHasErrors('token');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_right_token_creates_the_owner_and_signs_in()
    {
        $this->post('/setup', $this->form())
            ->assertRedirect(RouteServiceProvider::HOME);

        $owner = User::where('email', 'owner@example.com')->firstOrFail();

        $this->assertTrue($owner->isOwner());
        $this->assertSame('Olive Owner', $owner->name);
        $this->assertSame('Olive Studio', $owner->company->name);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('a-long-password-1', $owner->password));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_the_company_name_has_a_default()
    {
        $this->post('/setup', $this->form(['company' => '']))->assertRedirect();

        $this->assertSame("Olive Owner's Company", User::firstOrFail()->company->name);
    }

    public function test_the_password_confirmation_must_match()
    {
        $this->post('/setup', $this->form(['password_confirmation' => 'something-else-1']))
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_invalid_email_creates_no_account()
    {
        $this->post('/setup', $this->form(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_is_not_found_after_a_user_exists()
    {
        User::factory()->create();

        $this->get('/setup?token='.$this->token())->assertNotFound();
        $this->post('/setup', $this->form())->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_setup_is_not_found_when_users_exist_but_no_owner()
    {
        User::factory()->create(['is_owner' => false]);

        $this->get('/setup')->assertNotFound();
    }

    public function test_the_entry_pages_send_a_new_instance_to_setup()
    {
        $this->get('/')->assertRedirect('/setup');
        $this->get('/login')->assertRedirect('/setup');
        $this->get('/register')->assertRedirect('/setup');
    }

    public function test_open_registration_cannot_create_the_first_account_before_setup()
    {
        config(['snapsbrew.registration_enabled' => true]);

        $this->post('/register', [
            'name' => 'Stranger',
            'email' => 'stranger@example.com',
            'password' => 'a-long-password-1',
            'password_confirmation' => 'a-long-password-1',
        ])->assertRedirect('/setup');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_entry_pages_do_not_redirect_after_setup()
    {
        $this->post('/setup', $this->form());
        auth()->logout();

        $this->get('/')->assertRedirect('login');
        $this->get('/login')->assertOk();
    }

    public function test_other_pages_do_not_redirect_while_setup_is_needed()
    {
        $this->get('/documentation')->assertOk();
        $this->get('/forgot-password')->assertOk();
    }

    public function test_the_owner_action_creates_no_first_owner_when_a_user_appeared_meanwhile()
    {
        User::factory()->create(['email' => 'first@example.com']);

        $owner = app(CreateInstanceOwner::class)->createFirstOwner([
            'name' => 'Late Owner',
            'email' => 'late@example.com',
            'password' => 'a-long-password-1',
        ]);

        $this->assertNull($owner);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
    }
}

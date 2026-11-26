<?php

namespace Tests\Feature;

use App\Actions\Instance\CreateInstanceOwner;
use App\Contracts\SnapRenderer;
use App\Jobs\GenerateTemplatePreview;
use App\Models\Template;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use App\Support\Queues;
use App\Support\StarterTemplates;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * @return Collection<int, Template>
     */
    private function starterTemplatesOf(User $owner)
    {
        return Template::query()
            ->where('company_id', $owner->company_id)
            ->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')
            ->get();
    }

    /**
     * The owner also gets a copy of the welcome template, and that copy queues
     * its own preview. These checks count the starter templates only.
     */
    private function isStarterTemplate(Template $template): bool
    {
        return str_starts_with($template->title, StarterTemplates::TITLE_PREFIX);
    }

    public function test_the_setup_page_shows_the_form_with_the_right_token_from_the_url()
    {
        $this->get('/setup?token='.$this->token())
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertSee('type="hidden" name="token" value="'.$this->token().'"', false);
    }

    public function test_the_setup_page_without_a_token_tells_how_to_get_the_setup_url()
    {
        $this->get('/setup')
            ->assertOk()
            ->assertSee('Snapsbrew is not set up yet')
            ->assertSee('docker logs snapsbrew')
            ->assertSee('php artisan snapsbrew:setup-url')
            ->assertDontSee('name="password"', false);
    }

    public function test_the_setup_page_with_a_wrong_token_says_so_and_shows_no_form()
    {
        $this->get('/setup?token=abc123')
            ->assertOk()
            ->assertSee('This setup token is not correct.')
            ->assertSee('docker logs snapsbrew')
            ->assertDontSee('name="password"', false)
            ->assertDontSee('abc123');
    }

    public function test_a_new_instance_sends_the_home_page_to_the_friendly_setup_page()
    {
        $this->followingRedirects()
            ->get('/')
            ->assertOk()
            ->assertSee('Snapsbrew is not set up yet');
    }

    public function test_the_setup_page_offers_the_starter_templates_checked_by_default()
    {
        $this->get('/setup?token='.$this->token())
            ->assertOk()
            ->assertSee('Add the starter templates')
            ->assertSee('name="starter_templates" value="0"', false)
            ->assertSeeInOrder(['id="starter_templates"', 'value="1"', 'checked'], false);
    }

    public function test_the_owner_from_setup_skips_the_onboarding_wizard()
    {
        $this->post('/setup', $this->form())->assertRedirect(RouteServiceProvider::HOME);

        $owner = User::where('email', 'owner@example.com')->firstOrFail();

        $this->assertTrue($owner->hasCompletedOnboarding());
        $this->get('/dashboard')->assertOk();
        $this->get('/onboarding')->assertRedirect('/dashboard');
    }

    public function test_the_checkbox_adds_the_starter_templates_in_the_request_and_queues_their_previews()
    {
        Queue::fake();

        $this->post('/setup', $this->form(['starter_templates' => '1']))->assertRedirect();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $templates = $this->starterTemplatesOf($owner);

        $this->assertCount(6, $templates);
        $this->assertTrue($templates->every(fn (Template $template) => $template->user_id === $owner->id && $template->published_version_id !== null));
        $this->assertTrue($templates->every(fn (Template $template) => ! $template->hasMedia('preview')));

        Queue::assertPushedOn(Queues::DEFAULT, GenerateTemplatePreview::class);
        $this->assertCount(6, Queue::pushed(GenerateTemplatePreview::class, fn (GenerateTemplatePreview $job) => $this->isStarterTemplate($job->template)));
    }

    public function test_the_first_dashboard_load_shows_the_starter_templates()
    {
        Queue::fake();

        $this->post('/setup', $this->form(['starter_templates' => '1']))->assertRedirect();

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee(StarterTemplates::TITLE_PREFIX)
            ->assertSee('The preview is on its way.');
    }

    public function test_an_unchecked_box_adds_no_starter_templates()
    {
        Queue::fake();

        $this->post('/setup', $this->form(['starter_templates' => '0']))->assertRedirect();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertCount(0, $this->starterTemplatesOf($owner));
        Queue::assertNotPushed(GenerateTemplatePreview::class, fn (GenerateTemplatePreview $job) => $this->isStarterTemplate($job->template));
    }

    public function test_a_failed_setup_adds_no_starter_templates()
    {
        Queue::fake();

        $this->post('/setup', $this->form(['token' => 'wrong-token', 'starter_templates' => '1']))
            ->assertSessionHasErrors('token');

        $this->assertSame(0, Template::query()->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')->count());
        Queue::assertNotPushed(GenerateTemplatePreview::class);
    }

    public function test_the_queued_previews_render_for_the_six_starter_templates()
    {
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        $this->post('/setup', $this->form(['starter_templates' => '1']))->assertRedirect();

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $templates = $this->starterTemplatesOf($owner);

        $this->assertCount(6, $templates);
        $this->assertTrue($templates->every(fn (Template $template) => $template->hasMedia('preview')));
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

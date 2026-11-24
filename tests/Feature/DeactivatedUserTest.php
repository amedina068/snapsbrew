<?php

namespace Tests\Feature;

use App\Auth\ActiveUserProvider;
use App\Models\ApiKey;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * A deactivated user keeps every row, and loses every way in. Each test below
 * covers one entry point that authenticates as a user.
 */
class DeactivatedUserTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        $company = Company::factory()->create();

        return User::factory()->create(['company_id' => $company->id] + $attributes);
    }

    /**
     * Drop the guards that the earlier request built, so that the next request
     * authenticates from scratch the way a new HTTP request does.
     */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // ------------------------------------------------------------ the model

    public function test_deactivate_and_reactivate_toggle_the_timestamp()
    {
        $user = $this->user();
        $this->assertFalse($user->isDeactivated());

        $user->deactivate();
        $this->assertTrue($user->fresh()->isDeactivated());

        $user->reactivate();
        $this->assertFalse($user->fresh()->isDeactivated());
    }

    public function test_the_user_provider_does_not_return_a_deactivated_user()
    {
        $user = $this->user(['deactivated_at' => now()]);
        $user->forceFill(['remember_token' => 'remember-me'])->save();

        $provider = Auth::createUserProvider('users');

        $this->assertInstanceOf(ActiveUserProvider::class, $provider);
        $this->assertNull($provider->retrieveById($user->id));
        $this->assertNull($provider->retrieveByToken($user->id, 'remember-me'));

        $user->reactivate();
        $this->assertSame($user->id, $provider->retrieveById($user->id)?->id);
        $this->assertSame($user->id, $provider->retrieveByToken($user->id, 'remember-me')?->id);
    }

    // ------------------------------------------------------------ the login form

    public function test_a_deactivated_user_cannot_log_in_and_reads_why()
    {
        $user = $this->user(['deactivated_at' => now()]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);
        $this->assertGuest();
    }

    public function test_a_wrong_password_does_not_tell_that_the_account_is_deactivated()
    {
        $user = $this->user(['deactivated_at' => now()]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertNotSame(User::DEACTIVATED_MESSAGE, session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_a_reactivated_user_can_log_in_again()
    {
        $user = $this->user(['deactivated_at' => now()]);
        $user->reactivate();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_login_page_shows_the_message()
    {
        $this->completeInstanceSetup();

        $this->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['email' => User::DEACTIVATED_MESSAGE]))])
            ->get('/login')
            ->assertSee(User::DEACTIVATED_MESSAGE);
    }

    // ------------------------------------------------------------ an open web session

    public function test_an_open_session_ends_at_the_next_request()
    {
        $user = $this->user();
        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->deactivate();

        $this->get('/dashboard')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_stored_session_of_a_deactivated_user_is_logged_out_with_the_message()
    {
        $user = $this->user(['deactivated_at' => now()]);
        $sessionKey = Auth::guard('web')->getName();

        $this->withSession([$sessionKey => $user->id])
            ->get('/templates')
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => User::DEACTIVATED_MESSAGE])
            ->assertSessionMissing($sessionKey);

        $this->assertGuest();
    }

    public function test_a_json_request_from_a_deactivated_session_gets_a_401()
    {
        $user = $this->user();
        $this->actingAs($user);
        $user->deactivate();

        $this->getJson('/fonts/list')
            ->assertUnauthorized()
            ->assertJson(['message' => User::DEACTIVATED_MESSAGE]);
    }

    public function test_a_session_for_an_active_user_is_not_touched()
    {
        $user = $this->user();
        $sessionKey = Auth::guard('web')->getName();

        $this->withSession([$sessionKey => $user->id])->get('/dashboard')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    // ------------------------------------------------------------ Sanctum API tokens

    public function test_a_sanctum_token_stops_working_and_works_again_after_reactivation()
    {
        $user = $this->user();
        $token = $user->createToken('cli', ['api_requests'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth')->assertOk();

        $user->deactivate();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/auth')->assertUnauthorized();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/templates')->assertUnauthorized();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/guides/design')->assertUnauthorized();

        $user->reactivate();
        $this->freshRequest();
        $this->withToken($token)->getJson('/api/v1/auth')->assertOk();
    }

    public function test_a_sanctum_token_cannot_reach_the_mcp_server()
    {
        $user = $this->user();
        $token = $user->createToken('cli', ['api_requests'])->plainTextToken;
        $user->deactivate();

        $this->withToken($token)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized();
    }

    // ------------------------------------------------------------ signed URL keys

    public function test_a_signed_url_of_a_deactivated_user_returns_the_default_image()
    {
        $user = $this->user();
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);
        $plainKey = Str::random(40);
        ApiKey::factory()->signedUrl()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'key' => $plainKey,
        ]);
        $user->deactivate();

        $params = ['template_id' => $template->uuid, 'variants' => base64_encode(json_encode(['title' => 'Hi']))];
        $fields = collect($params)->sortKeys()->map(fn ($value, $key) => "{$key}={$value}")->implode('&');
        $url = '/signed-url/image.jpg?'.http_build_query($params + ['signature' => hash_hmac('sha256', $fields, $plainKey)]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertDatabaseMissing('api_logs', ['user_id' => $user->id]);
    }

    // ------------------------------------------------------------ embedded forms

    private function form(User $user): Form
    {
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        return Form::factory()->forTemplate($template)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
    }

    public function test_the_embedded_form_page_of_a_deactivated_user_is_not_found()
    {
        $user = $this->user();
        $form = $this->form($user);
        $url = URL::signedRoute('signedEmbeddedForm', ['formUuid' => $form->uuid]);

        $this->get($url)->assertOk();

        $user->deactivate();

        $this->get($url)->assertNotFound();
    }

    public function test_the_embedded_form_submit_of_a_deactivated_user_is_not_found()
    {
        $user = $this->user();
        $form = $this->form($user);

        // An active owner: the middleware logs the call before the controller
        // reads the signature.
        $this->postJson("/api/v1/embedded-forms/{$form->uuid}?signature=invalid")->assertNotFound();
        $this->assertDatabaseHas('api_logs', ['user_id' => $user->id, 'type' => 'embedded_form']);

        $user->deactivate();
        ApiLog::query()->delete();

        $this->postJson(URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]))
            ->assertNotFound();
        $this->assertDatabaseMissing('api_logs', ['user_id' => $user->id]);
    }
}

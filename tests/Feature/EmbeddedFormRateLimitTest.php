<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Form;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * The embedded form routes take no credential except a signed URL that the
 * customer puts on a public page. Each submit renders on the account of the
 * form owner, so a script could render without end. The `embedded-form-submit`
 * limiter caps the submits per IP and per form, and the `embedded-form-view`
 * limiter caps the page views.
 */
class EmbeddedFormRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->renderer = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $this->renderer);
    }

    private function form(): Form
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        return Form::factory()->forTemplate($template)->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
        ]);
    }

    private function submit(Form $form, string $ip = '203.0.113.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]));
    }

    private function viewForm(Form $form, string $ip = '203.0.113.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get(URL::signedRoute('signedEmbeddedForm', ['formUuid' => $form->uuid]));
    }

    public function test_the_config_has_defaults_for_every_limit()
    {
        foreach (['submits_per_ip', 'submits_per_form', 'views_per_ip', 'views_per_form'] as $key) {
            $this->assertIsInt(config("snapsbrew.embedded_form_rate_limits.{$key}"), $key);
            $this->assertGreaterThan(0, config("snapsbrew.embedded_form_rate_limits.{$key}"), $key);
        }
    }

    public function test_the_submit_limit_per_ip_answers_429_and_renders_nothing()
    {
        config([
            'snapsbrew.embedded_form_rate_limits.submits_per_ip' => 2,
            'snapsbrew.embedded_form_rate_limits.submits_per_form' => 100,
        ]);
        $form = $this->form();
        $other = $this->form();

        $this->submit($form)->assertCreated();
        $this->submit($form)->assertCreated();

        $limited = $this->submit($form);
        $limited->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited')
            ->assertJsonStructure(['message', 'code']);
        $this->assertNotNull($limited->headers->get('Retry-After'));

        // The limit is per IP, so another form from the same IP is limited too.
        $this->submit($other)->assertStatus(429);

        // A different IP still gets through.
        $this->submit($form, '198.51.100.20')->assertCreated();

        $this->assertCount(3, $this->renderer->screenshotCalls);
        $this->assertDatabaseCount('api_logs', 3);
    }

    public function test_the_submit_limit_per_form_holds_across_ips()
    {
        config([
            'snapsbrew.embedded_form_rate_limits.submits_per_ip' => 100,
            'snapsbrew.embedded_form_rate_limits.submits_per_form' => 2,
        ]);
        $form = $this->form();
        $other = $this->form();

        $this->submit($form, '203.0.113.1')->assertCreated();
        $this->submit($form, '203.0.113.2')->assertCreated();
        $this->submit($form, '203.0.113.3')->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        // Another form has its own budget.
        $this->submit($other, '203.0.113.3')->assertCreated();

        $this->assertCount(3, $this->renderer->screenshotCalls);
    }

    public function test_a_submit_with_a_bad_signature_counts_against_the_limit()
    {
        config(['snapsbrew.embedded_form_rate_limits.submits_per_ip' => 1]);
        $form = $this->form();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson("/api/v1/embedded-forms/{$form->uuid}?signature=invalid")
            ->assertNotFound();

        $this->submit($form)->assertStatus(429);
        $this->assertCount(0, $this->renderer->screenshotCalls);
    }

    public function test_the_view_limit_per_ip_answers_429()
    {
        config([
            'snapsbrew.embedded_form_rate_limits.views_per_ip' => 2,
            'snapsbrew.embedded_form_rate_limits.views_per_form' => 100,
        ]);
        $form = $this->form();

        $this->viewForm($form)->assertOk();
        $this->viewForm($form)->assertOk();
        $this->viewForm($form)->assertStatus(429);
        $this->viewForm($form, '198.51.100.20')->assertOk();
    }

    public function test_the_view_limit_per_form_holds_across_ips()
    {
        config([
            'snapsbrew.embedded_form_rate_limits.views_per_ip' => 100,
            'snapsbrew.embedded_form_rate_limits.views_per_form' => 2,
        ]);
        $form = $this->form();

        $this->viewForm($form, '203.0.113.1')->assertOk();
        $this->viewForm($form, '203.0.113.2')->assertOk();
        $this->viewForm($form, '203.0.113.3')->assertStatus(429);
        $this->viewForm($this->form(), '203.0.113.3')->assertOk();
    }
}

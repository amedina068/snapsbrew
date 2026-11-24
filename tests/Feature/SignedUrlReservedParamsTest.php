<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Models\ApiKey;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * A public signed URL sits in an `og:image` tag, so anyone can read it. The
 * signature leaves out `identifier` and `api_log_uuid`, which the middleware
 * sets itself. Before this change a guest added one of them with a new value
 * on each request, and each value missed the cache and forced a new render.
 */
class SignedUrlReservedParamsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Template $template;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->app->instance(SnapRenderer::class, new FakeRenderer);

        $company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $company->id]);
        $this->template = Template::factory()->create(['user_id' => $this->user->id, 'company_id' => $company->id]);
        $plainKey = Str::random(40);
        ApiKey::factory()->signedUrl()->create(['user_id' => $this->user->id, 'company_id' => $company->id, 'key' => $plainKey]);

        $params = ['template_id' => $this->template->uuid, 'variants' => base64_encode(json_encode([]))];
        $fields = collect($params)->sortKeys()->map(fn ($v, $k) => "{$k}={$v}")->implode('&');
        $this->url = '/signed-url/image.jpg?'.http_build_query($params + ['signature' => hash_hmac('sha256', $fields, $plainKey)]);
    }

    private function snaps(): int
    {
        return Snap::where('template_id', $this->template->id)->count();
    }

    public function test_the_public_url_renders_one_time_and_then_hits_the_cache()
    {
        $this->get($this->url)->assertRedirect();
        $this->get($this->url)->assertRedirect();

        $this->assertSame(1, $this->snaps());
    }

    public function test_a_reserved_parameter_is_refused_before_a_render()
    {
        $this->get($this->url)->assertRedirect();

        foreach (['identifier', 'api_log_uuid', 'IDENTIFIER'] as $name) {
            foreach (range(1, 3) as $i) {
                $this->get($this->url.'&'.$name.'=x'.$i)->assertStatus(400);
            }
        }

        $this->assertSame(1, $this->snaps());
        $this->assertSame(1, (int) ApiLog::where('user_id', $this->user->id)->sum('renders'));
    }

    public function test_a_reserved_parameter_in_array_form_is_refused()
    {
        $this->get($this->url.'&identifier[]=x')->assertStatus(400);
        $this->assertSame(0, $this->snaps());
    }

    public function test_the_route_has_a_per_ip_throttle()
    {
        config(['snapsbrew.signed_url_requests_per_ip' => 3]);

        foreach (range(1, 3) as $i) {
            $this->get($this->url)->assertRedirect();
        }

        $this->get($this->url)->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])->get($this->url)->assertRedirect();
    }

    public function test_the_default_throttle_is_high_enough_for_link_preview_crawlers()
    {
        $this->assertGreaterThanOrEqual(300, config('snapsbrew.signed_url_requests_per_ip'));
    }
}

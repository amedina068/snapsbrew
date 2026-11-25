<?php

namespace Tests\Feature;

use App\Contracts\SnapRenderer;
use App\Jobs\InstallStarterTemplates;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use App\Support\Queues;
use App\Support\StarterTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * The setup page no longer queues this job. Other callers that must not wait
 * for the preview images can still queue it.
 */
class InstallStarterTemplatesJobTest extends TestCase
{
    use RefreshDatabase;

    private function starterTemplatesOf(User $user)
    {
        return Template::query()
            ->where('company_id', $user->company_id)
            ->where('title', 'like', StarterTemplates::TITLE_PREFIX.'%')
            ->get();
    }

    public function test_the_job_goes_on_the_default_queue()
    {
        Queue::fake();
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        InstallStarterTemplates::dispatch($user);

        Queue::assertPushedOn(Queues::DEFAULT, InstallStarterTemplates::class, fn (InstallStarterTemplates $job) => $job->user->is($user));
    }

    public function test_the_job_adds_the_starter_templates_with_their_previews_one_time()
    {
        $this->app->instance(SnapRenderer::class, new FakeRenderer);
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        InstallStarterTemplates::dispatchSync($user);
        InstallStarterTemplates::dispatchSync($user);

        $templates = $this->starterTemplatesOf($user);

        $this->assertCount(count(StarterTemplates::all()), $templates);
        $this->assertTrue($templates->every(fn (Template $template) => $template->hasMedia('preview')));
    }
}

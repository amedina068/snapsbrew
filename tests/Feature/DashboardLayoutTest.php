<?php

namespace Tests\Feature;

use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The frame that every signed-in page shares: the language, the document
 * title, the one level-one heading, the one banner and the flash messages.
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => 'Snapsbrew']);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function template(array $attributes = []): Template
    {
        return Template::factory()->create($attributes + [
            'user_id' => $this->user->id,
            'company_id' => $this->user->company_id,
        ]);
    }

    public function test_the_page_declares_its_language(): void
    {
        $this->get('/templates')->assertSee('<html lang="en"', false);
    }

    public function test_the_document_title_names_the_page(): void
    {
        $this->get('/templates')->assertSee('<title>Templates - Snapsbrew</title>', false);
    }

    public function test_a_page_with_its_own_heading_gets_the_text_of_that_heading_as_its_title(): void
    {
        $template = $this->template(['title' => 'Spring & Summer']);

        $this->get("/templates/{$template->uuid}")
            ->assertSee('<title>Spring &amp; Summer - Snapsbrew</title>', false);
    }

    public function test_a_page_with_its_own_heading_has_one_level_one_heading(): void
    {
        $template = $this->template();

        $html = $this->get("/templates/{$template->uuid}")->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_top_bar_is_the_only_banner(): void
    {
        $html = $this->get('/templates')->getContent();

        $this->assertSame(1, substr_count($html, '<header'));
    }

    public function test_a_success_message_shows_on_a_page_that_had_no_message_area(): void
    {
        $template = $this->template();

        $this->withSession(['success' => 'The snap is deleted.'])
            ->get("/templates/{$template->uuid}")
            ->assertSee('role="status"', false)
            ->assertSee('The snap is deleted.');
    }

    public function test_an_error_message_shows_one_time_as_an_alert(): void
    {
        $template = $this->template(['layers' => json_encode([])]);

        $this->get("/templates/{$template->uuid}/forms/new")
            ->assertRedirect("templates/{$template->uuid}/forms");

        $html = $this->get("/templates/{$template->uuid}/forms")
            ->assertSee('Add at least one layer to the template before creating a form.')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'role="alert"'));
    }

    public function test_log_out_is_an_item_of_the_account_menu(): void
    {
        $this->get('/templates')->assertSee('logout-form="logout-form"', false);
    }
}

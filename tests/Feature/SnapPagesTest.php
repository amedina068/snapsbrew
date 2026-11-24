<?php

namespace Tests\Feature;

use App\Models\Snap;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The snap list, the snap page and the snap delete actions of the web app.
 */
class SnapPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Template $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->template = Template::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->user->company_id,
        ]);
        $this->actingAs($this->user);
    }

    private function snap(string $state = 'completed', array $attributes = []): Snap
    {
        return Snap::factory()->{$state}()->create($attributes + [
            'user_id' => $this->user->id,
            'template_id' => $this->template->id,
        ]);
    }

    public function test_a_bulk_delete_with_no_snap_selected_returns_a_message_and_not_an_error(): void
    {
        $this->from("/templates/{$this->template->uuid}")
            ->delete('/snapshots', [])
            ->assertRedirect("/templates/{$this->template->uuid}")
            ->assertSessionHasErrors(['snaps_ids' => 'Select at least one snap to delete.']);

        $this->get("/templates/{$this->template->uuid}")
            ->assertSee('Select at least one snap to delete.');
    }

    public function test_a_bulk_delete_deletes_only_the_selected_snaps(): void
    {
        $selected = $this->snap();
        $kept = $this->snap();

        $this->from("/templates/{$this->template->uuid}")
            ->delete('/snapshots', ['snaps_ids' => [$selected->uuid]])
            ->assertSessionHas('success', 'The selected snaps are deleted.');

        $this->assertModelMissing($selected);
        $this->assertModelExists($kept);
    }

    public function test_a_single_delete_asks_for_a_confirmation(): void
    {
        $this->snap();

        $this->get("/templates/{$this->template->uuid}")
            ->assertSee("return confirm('Delete this snap? You cannot undo this.')", false)
            ->assertSee("return confirm('Delete the selected snaps? You cannot undo this.')", false);
    }

    public function test_a_snap_with_no_image_shows_its_status_instead_of_an_empty_image(): void
    {
        $this->snap('failed', ['identifier' => 'broken-one']);
        $this->snap('processing', ['identifier' => 'busy-one']);

        $html = $this->get('/snapshots')
            ->assertSee('This snap did not render.')
            ->assertSee('The image is not ready yet.')
            ->assertSee('Snap broken-one:')
            ->getContent();

        $this->assertStringNotContainsString('src=""', $html);
    }

    public function test_the_snap_page_of_a_pending_snap_says_that_the_image_is_not_ready(): void
    {
        $snap = $this->snap('processing');

        $this->get("/snapshots/{$snap->uuid}")
            ->assertOk()
            ->assertSee('The image is not ready yet')
            ->assertDontSee('Image link');
    }

    public function test_the_pages_call_a_snap_a_snap(): void
    {
        $this->get('/snapshots')
            ->assertSee('Make your first snap')
            ->assertDontSee('Snapshot');
    }

    public function test_the_empty_states_have_no_dead_links(): void
    {
        foreach (['/snapshots', '/screenshots', "/templates/{$this->template->uuid}/imports"] as $page) {
            $html = $this->get($page)->assertOk()->getContent();

            $this->assertStringNotContainsString('href="#"', $html, $page);
            $this->assertStringNotContainsString('/product/embedded-form', $html, $page);
        }

        $this->get('/documentation/guides/embedded-forms')->assertOk();
    }
}

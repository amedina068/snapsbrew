<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Snap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A snap rendered from an inline design has no template. Every web read path
 * that shows a snap must open it without dereferencing one.
 */
class InlineSnapReadPathsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_snapshot_page_opens_an_inline_snap_and_shows_no_template_link()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $snap = Snap::factory()->inline()->completed()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $response = $this->actingAs($user)->get("/snapshots/{$snap->uuid}");

        $response->assertOk();
        $response->assertSee($snap->uuid);
        $response->assertDontSee('/templates/');
    }

    public function test_the_snapshot_index_lists_an_inline_snap()
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $snap = Snap::factory()->inline()->completed()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $this->actingAs($user)->get('/snapshots')->assertOk()->assertSee($snap->uuid);
    }
}

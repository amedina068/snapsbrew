<?php

namespace Tests\Feature\Api;

use App\Exceptions\EditorDocumentConflict;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TemplateDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return [$user, $user->createToken('test', ['api_requests'])->plainTextToken];
    }

    public function test_document_cas_returns_the_new_revision(): void
    {
        [$user, $token] = $this->actor();
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 0,
                'document' => ['version' => 2, 'layers' => []],
            ])
            ->assertOk()
            ->assertJsonPath('revision', 1)
            ->assertJsonPath('document.version', 2);

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'editor_revision' => 1, 'editor_schema_version' => 2]);
    }

    /**
     * A stale write answers 409 and leaves the row exactly as it was.
     *
     * The response half and the row half are two separate claims. A write that
     * lands before the revision check still answers 409, so the response alone
     * cannot tell an intact row from an overwritten one. The four columns
     * `replace()` writes are read back from the raw row and compared against
     * their values from before the call.
     */
    public function test_document_cas_returns_canonical_conflict_without_overwriting(): void
    {
        [$user, $token] = $this->actor();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'editor_revision' => 3,
            'editor_schema_version' => 2,
            'editor_document' => ['version' => 2, 'layers' => [['id' => 'current']]],
            // Both columns hold a value the projection would replace. With a
            // null on each side an overwritten row still reads as unchanged.
            'layers' => json_encode([['key' => 'current', 'properties' => []]]),
            'groups' => json_encode([['id' => 'g1', 'name' => 'Header']]),
        ]);

        // Read the stored row, not the model, so no cast hides a changed value.
        $before = DB::table('templates')->where('id', $template->id)->first();

        // The body must be well formed to reach the revision check. `replace()`
        // validates the request document before it locks the row, so a stale and
        // malformed request answers 422. This test guards the conflict, not the
        // shape of the layer, so the layer carries the shape the rules require.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 2,
                'document' => ['version' => 2, 'layers' => [['key' => 'stale', 'type' => 'text', 'properties' => []]]],
            ])
            ->assertStatus(409)
            ->assertExactJson([
                'code' => 'editor_document_conflict',
                'message' => EditorDocumentConflict::MESSAGE,
                'current_revision' => 3,
                'current_document' => ['version' => 2, 'layers' => [['id' => 'current']]],
            ]);

        $after = DB::table('templates')->where('id', $template->id)->first();

        $this->assertSame($before->editor_document, $after->editor_document);
        $this->assertSame($before->editor_revision, $after->editor_revision);
        $this->assertSame($before->layers, $after->layers);
        $this->assertSame($before->groups, $after->groups);
    }

    public function test_document_cas_is_scoped_to_the_callers_company(): void
    {
        [, $token] = $this->actor();
        $other = Template::factory()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$other->uuid}/document")
            ->assertNotFound();
    }

    /**
     * The read gate refuses an account that cannot act as a customer.
     *
     * This test sent a company peer before, and the peer was refused. That was
     * the defect: the write gate reads the company and the read gate read the
     * individual account, so a peer wrote the document and could not read the
     * `revision` that the write needs.
     *
     * `actsAsCustomer()` is the one condition the read gate still answers on
     * its own, so this test sends the only caller that fails it. The role
     * `viewer` is written inline because no factory state builds that user.
     * `TemplateWriteGuardTest::blockedToken()` does the same for the writes.
     */
    public function test_document_read_refuses_a_company_member_that_cannot_act_as_a_customer(): void
    {
        [$owner] = $this->actor();
        $template = Template::factory()->create(['user_id' => $owner->id, 'company_id' => $owner->company_id]);
        $blocked = User::factory()->create(['company_id' => $owner->company_id, 'role' => 'viewer']);
        $token = $blocked->createToken('blocked', ['api_requests'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}/document")
            ->assertForbidden();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}/document/preflight")
            ->assertForbidden();
    }

    /**
     * A company peer reads the document and the preflight.
     *
     * `TemplatePolicy::update` reads the company, so the peer already wrote
     * both document routes. This pins the permissive side of the read gate,
     * which is the side the defect broke.
     */
    public function test_a_company_peer_who_does_not_own_the_template_can_read_the_document(): void
    {
        [$owner] = $this->actor();
        $template = Template::factory()->create([
            'user_id' => $owner->id,
            'company_id' => $owner->company_id,
            'editor_revision' => 4,
            'editor_schema_version' => 2,
            'editor_document' => ['version' => 2, 'layers' => []],
        ]);
        $peer = User::factory()->create(['company_id' => $owner->company_id]);
        $token = $peer->createToken('peer', ['api_requests'])->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}/document")
            ->assertOk()
            ->assertJsonPath('revision', 4)
            ->assertJsonPath('document.version', 2);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}/document/preflight")
            ->assertOk()
            ->assertJsonPath('revision', 4);
    }

    /**
     * The round trip the defect broke.
     *
     * A write is a compare-and-swap and the read returns the only `revision`
     * that makes it safe. The peer reads that value and writes with it. A read
     * gate narrower than the write gate makes this sequence impossible.
     */
    public function test_a_company_peer_reads_the_revision_and_writes_with_it(): void
    {
        [$owner] = $this->actor();
        $template = Template::factory()->create([
            'user_id' => $owner->id,
            'company_id' => $owner->company_id,
            'editor_revision' => 2,
            'editor_schema_version' => 2,
            'editor_document' => ['version' => 2, 'layers' => []],
        ]);
        $peer = User::factory()->create(['company_id' => $owner->company_id]);
        $token = $peer->createToken('peer', ['api_requests'])->plainTextToken;

        $revision = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}/document")
            ->assertOk()
            ->json('revision');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => $revision,
                'document' => ['version' => 2, 'layers' => [['key' => 'headline', 'type' => 'text', 'properties' => []]]],
            ])
            ->assertOk()
            ->assertJsonPath('revision', $revision + 1);

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'editor_revision' => 3]);
    }

    /**
     * A caller from another company reaches neither read.
     *
     * The company-scoped fetch answers first, so the status is 404 and not
     * 403. The widened gate does not change that, and this test proves it.
     */
    public function test_a_user_of_another_company_reaches_neither_read(): void
    {
        [, $token] = $this->actor();
        $other = Template::factory()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$other->uuid}/document")
            ->assertNotFound();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$other->uuid}/document/preflight")
            ->assertNotFound();
    }

    public function test_preflight_returns_document_issues_for_an_authorized_user(): void
    {
        [$user, $token] = $this->actor();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'editor_document' => ['layers' => [['type' => 'image', 'source' => ['url' => 'https://example.test/image.png']]]],
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/templates/{$template->uuid}/document/preflight")
            ->assertOk()
            ->assertJsonPath('issues.0.code', 'image_asset_missing')
            ->assertJsonPath('issues.0.path', '/layers/0/source');
    }

    public function test_document_budget_rejects_an_oversized_payload(): void
    {
        [$user, $token] = $this->actor();
        $template = Template::factory()->create(['user_id' => $user->id, 'company_id' => $user->company_id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/v1/templates/{$template->uuid}/document", [
                'revision' => 0,
                'document' => ['version' => 2, 'text' => str_repeat('x', 17000)],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');
    }
}

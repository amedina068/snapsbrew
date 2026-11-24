<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Form;
use App\Models\TemplateCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * A collection can lose all of its templates, for example through
 * "Remove from collection". A render request for that collection must say so,
 * not answer 500.
 *
 * Before, `POST /v1/snaps` called its private `response()` helper with three
 * arguments where it needs four, and the embedded form controller called a
 * `response()` method that it does not have. Both threw, and both answered 500.
 */
class EmptyCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_a_snap_request_for_an_empty_collection_answers_404_with_a_message(): void
    {
        [$user, $collection] = $this->emptyCollection();
        $token = $user->createToken('test', ['api_requests'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/snaps', ['collection_id' => $collection->uuid])
            ->assertNotFound()
            ->assertExactJson(['message' => 'The collection doesn\'t have any templates.']);
    }

    public function test_an_embedded_form_of_an_empty_collection_answers_422_with_a_message(): void
    {
        [$user, $collection] = $this->emptyCollection();
        $form = Form::factory()->forCollection($collection)->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        $this->postJson(URL::signedRoute('submitSignedEmbeddedForm', ['formUuid' => $form->uuid]))
            ->assertStatus(422)
            ->assertJsonPath('message', 'The form does not include any templates.');
    }

    /** @return array{0: User, 1: TemplateCollection} */
    private function emptyCollection(): array
    {
        $user = User::factory()->create(['company_id' => Company::factory()->create()->id]);
        $collection = TemplateCollection::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        return [$user, $collection];
    }
}

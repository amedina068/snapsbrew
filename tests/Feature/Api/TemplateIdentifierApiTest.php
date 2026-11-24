<?php

namespace Tests\Feature\Api;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

/**
 * `PATCH /v1/templates/{templateIdentifier}` and
 * `DELETE /v1/templates/{templateIdentifier}` accept the template UUID and the
 * numeric template ID.
 *
 * The public API returns the UUID and never returns the numeric ID, so a REST
 * client holds only the UUID. The web application URL holds the numeric ID.
 * Both callers exist, so both identifiers must work.
 */
class TemplateIdentifierApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A UUID whose first character is a digit.
     *
     * `App\Traits\HasUuid` writes `Str::uuid()`, which is a version 4 UUID, so
     * the first character is one random hex digit. Ten of the sixteen values
     * are digits. MySQL reads this value as the number 4 when it compares it
     * to an integer column.
     */
    private const UUID_THAT_READS_AS_FOUR = '4f3a1b2c-0000-4000-8000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createApiUser(): array
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $token = $user->createToken('test', ['api_requests']);

        return [$user, $token->plainTextToken];
    }

    /**
     * Create one template and give it the UUID the test names.
     *
     * `HasUuid::bootHasUuid()` writes a fresh UUID on every create, so a UUID
     * passed to the factory never reaches the row. Write it after the create.
     */
    private function createTemplate(User $user, array $attributes = [], ?string $uuid = null): Template
    {
        $template = Template::factory()->create($attributes + [
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);

        if ($uuid !== null) {
            Template::query()->whereKey($template->id)->update(['uuid' => $uuid]);
            $template->refresh();
        }

        return $template;
    }

    public function test_patch_by_uuid_updates_the_template()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->createTemplate($user, ['title' => 'Before']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->uuid}", ['title' => 'After'])
            ->assertStatus(200);

        $this->assertSame('After', $template->fresh()->title);
    }

    public function test_patch_by_numeric_id_updates_the_template()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->createTemplate($user, ['title' => 'Before']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$template->id}", ['title' => 'After'])
            ->assertStatus(200);

        $this->assertSame('After', $template->fresh()->title);
    }

    public function test_delete_by_uuid_deletes_the_template()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->createTemplate($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/templates/{$template->uuid}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_delete_by_numeric_id_deletes_the_template()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->createTemplate($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/templates/{$template->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('templates', ['id' => $template->id]);
    }

    public function test_patch_with_another_company_uuid_answers_404_and_changes_nothing()
    {
        [, $token] = $this->createApiUser();
        $stranger = Template::factory()->create(['title' => 'Untouched']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson("/api/v1/templates/{$stranger->uuid}", ['title' => 'Stolen'])
            ->assertStatus(404);

        $this->assertSame('Untouched', $stranger->fresh()->title);
    }

    public function test_delete_with_another_company_uuid_answers_404_and_changes_nothing()
    {
        [, $token] = $this->createApiUser();
        $stranger = Template::factory()->create();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/v1/templates/{$stranger->uuid}")
            ->assertStatus(404);

        $this->assertDatabaseHas('templates', ['id' => $stranger->id]);
    }

    public function test_patch_with_a_value_that_is_neither_identifier_answers_404_and_changes_nothing()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->createTemplate($user, ['title' => 'Untouched']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/templates/not-an-identifier', ['title' => 'Renamed'])
            ->assertStatus(404);

        $this->assertSame('Untouched', $template->fresh()->title);
    }

    public function test_delete_with_a_value_that_is_neither_identifier_answers_404_and_changes_nothing()
    {
        [$user, $token] = $this->createApiUser();
        $template = $this->createTemplate($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson('/api/v1/templates/not-an-identifier')
            ->assertStatus(404);

        $this->assertDatabaseHas('templates', ['id' => $template->id]);
    }

    /**
     * The lookup compares one column, and the branch picks which one.
     *
     * This reads the query the scope builds, so it holds on every driver. The
     * behavioral proof below runs on MySQL only, because SQLite converts a
     * text value to a number only when the whole text is a number, and a UUID
     * never is. A test of the response alone therefore cannot fail on SQLite.
     */
    public function test_the_lookup_compares_one_column_and_the_value_picks_it()
    {
        $this->assertSame(
            [['type' => 'Basic', 'column' => 'uuid', 'operator' => '=', 'value' => self::UUID_THAT_READS_AS_FOUR, 'boolean' => 'and']],
            Template::query()->identifier(self::UUID_THAT_READS_AS_FOUR)->getQuery()->wheres
        );

        $this->assertSame(
            [['type' => 'Basic', 'column' => 'id', 'operator' => '=', 'value' => '42', 'boolean' => 'and']],
            Template::query()->identifier('42')->getQuery()->wheres
        );
    }

    /**
     * A UUID must not match a template by its numeric ID.
     *
     * MySQL casts a string to a number when it compares it to an integer
     * column. Measured on MySQL 8 with a two-row table:
     * `SELECT * FROM cast_probe WHERE id = '4f3a1b2c-0000-4000-8000-000000000000'`
     * returned the row with `id` 4 and warned
     * `Truncated incorrect DOUBLE value`.
     *
     * The row with `id` 4 sorts before the row the caller named, so a lookup
     * that compares the value against both columns renames the wrong
     * template and leaves the named one untouched.
     */
    public function test_a_uuid_does_not_match_a_template_by_its_numeric_id()
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('The string-to-integer cast this test measures belongs to MySQL.');
        }

        [$user, $token] = $this->createApiUser();
        $decoy = $this->createTemplate($user, ['title' => 'Decoy']);
        $uuid = self::uuidThatReadsAs($decoy->id);
        $target = $this->createTemplate($user, ['title' => 'Target'], $uuid);

        $this->assertGreaterThan($decoy->id, $target->id, 'The decoy must sort first, so a wrong match is visible.');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson('/api/v1/templates/'.$uuid, ['title' => 'Renamed'])
            ->assertStatus(200);

        $this->assertSame('Renamed', $target->fresh()->title);
        $this->assertSame('Decoy', $decoy->fresh()->title);
    }

    /**
     * Build a UUID that MySQL reads as the number `$id`.
     *
     * The first segment of a UUID holds eight hexadecimal characters. The
     * leading digits are the number the cast produces, and the letter after
     * them stops the cast.
     */
    private static function uuidThatReadsAs(int $id): string
    {
        return str_pad($id.'f', 8, 'a').'-0000-4000-8000-000000000000';
    }
}

<?php

namespace Tests\Feature\Mcp;

use App\Contracts\SnapRenderer;
use App\Jobs\GenerateTemplateVersionPreview;
use App\Jobs\ProcessSnap;
use App\Mcp\Servers\SnapsbrewServer;
use App\Mcp\Tools\CreateSnapTool;
use App\Mcp\Tools\CreateTemplateTool;
use App\Mcp\Tools\DescribeLayersTool;
use App\Mcp\Tools\DesignGuideTool;
use App\Mcp\Tools\EditTemplateTool;
use App\Mcp\Tools\GetEditorDocumentTool;
use App\Mcp\Tools\GetTemplateDetailsTool;
use App\Mcp\Tools\ImagePurposeGuideTool;
use App\Mcp\Tools\ListTemplateSizesTool;
use App\Mcp\Tools\ListTemplatesTool;
use App\Mcp\Tools\ListVariantPropertiesTool;
use App\Mcp\Tools\PlatformGuideTool;
use App\Mcp\Tools\PreviewSnapTool;
use App\Mcp\Tools\PreviewTemplateTool;
use App\Mcp\Tools\PublishTemplateTool;
use App\Mcp\Tools\PutEditorDocumentTool;
use App\Mcp\Tools\WhoAmITool;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\SnapSourceVersioner;
use App\Services\UsageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class McpToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);
    }

    private function makeUser(): User
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        return $user;
    }

    private function template(User $user): Template
    {
        return Template::factory()->withLayers([
            ['key' => 'headline', 'type' => 'text', 'properties' => [
                ['name' => 'textContent', 'value' => 'Hi'],
            ]],
        ])->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'width' => '100',
            'height' => '100',
            // The factory picks a random preset. Name one here, so every test
            // that resizes this template reads the same start state.
            // `open_graph` shares 1200x630 with `facebook_feed`, which is the
            // pair that broke `Template::booted()`.
            'size' => 'open_graph',
        ]);
    }

    public function test_whoami_returns_the_user_and_company()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(WhoAmITool::class)
            ->assertOk()
            ->assertSee($user->email)
            ->assertSee($user->company->name)
            ->assertDontSee('remaining_credits');
    }

    public function test_list_variant_properties_returns_catalog_with_examples()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ListVariantPropertiesTool::class)
            ->assertOk()
            ->assertSee('image_url')
            ->assertSee('background_color')
            ->assertSee('qr_content')
            ->assertSee('hidden')
            ->assertSee('example');
    }

    public function test_list_templates_scoped_to_company()
    {
        $user = $this->makeUser();
        $this->template($user)->update(['title' => 'Mine']);
        Template::factory()->create(['title' => 'Theirs']); // other company

        SnapsbrewServer::actingAs($user)->tool(ListTemplatesTool::class)
            ->assertOk()
            ->assertSee('Mine')
            ->assertDontSee('Theirs');
    }

    public function test_list_templates_empty_for_company_with_no_templates()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ListTemplatesTool::class)
            ->assertOk()
            ->assertSee('"count": 0');
    }

    public function test_get_template_details_returns_layer_keys()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(GetTemplateDetailsTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('headline');
    }

    public function test_get_template_details_errors_for_other_company()
    {
        $user = $this->makeUser();
        $other = Template::factory()->create();

        SnapsbrewServer::actingAs($user)->tool(GetTemplateDetailsTool::class, [
            'template_id' => $other->uuid,
        ])->assertHasErrors();
    }

    /**
     * A caller from another company reads no editor document.
     *
     * `companyTemplate()` answers first, so the tool reports the template as
     * not found and never reaches the gate. The widened `view` rule changes
     * nothing here, and this test proves it.
     */
    public function test_get_editor_document_errors_for_other_company()
    {
        $user = $this->makeUser();
        $other = Template::factory()->create();

        SnapsbrewServer::actingAs($user)->tool(GetEditorDocumentTool::class, [
            'template_id' => $other->uuid,
        ])->assertHasErrors();
    }

    public function test_editor_document_tools_round_trip_and_report_a_cas_conflict()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(PutEditorDocumentTool::class, [
            'template_id' => $template->uuid,
            'revision' => 0,
            'document' => ['version' => 2, 'layers' => []],
        ])->assertOk()->assertSee('"revision": 1');

        SnapsbrewServer::actingAs($user)->tool(GetEditorDocumentTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"revision": 1');

        SnapsbrewServer::actingAs($user)->tool(PutEditorDocumentTool::class, [
            'template_id' => $template->uuid,
            'revision' => 0,
            // A well-formed layer, because `replace()` validates the document
            // before it reads the revision. This step guards the conflict report.
            'document' => ['version' => 2, 'layers' => [['key' => 'stale', 'type' => 'text', 'properties' => []]]],
        ])->assertHasErrors()->assertSee('editor_document_conflict')->assertSee('current_revision');
    }

    public function test_put_editor_document_projects_direct_layers_to_the_immutable_snap_source(): void
    {
        $user = $this->makeUser();
        $template = $this->template($user);
        $document = [
            'version' => 2,
            'layers' => [
                [
                    'key' => 'hero',
                    'type' => 'image',
                    'groupId' => 'visuals',
                    'properties' => [
                        ['name' => 'backgroundImage', 'value' => 'data:image/png;base64,AA=='],
                        ['name' => 'imageFit', 'value' => 'cover'],
                        ['name' => 'imageFocalX', 'value' => 31],
                        ['name' => 'imageFocalY', 'value' => 67],
                        ['name' => 'imageMask', 'value' => 'rounded'],
                        ['name' => 'rotate', 'value' => 12],
                        ['name' => 'clipContent', 'value' => true],
                    ],
                ],
                [
                    'key' => 'headline',
                    'type' => 'text',
                    'properties' => [
                        ['name' => 'textContent', 'value' => 'MCP projection'],
                        ['name' => 'fontWeight', 'value' => 700],
                        ['name' => 'fontStyle', 'value' => 'italic'],
                        ['name' => 'letterSpacing', 'value' => 2],
                        ['name' => 'effects', 'value' => ['blur' => 2, 'stroke' => ['width' => 1, 'style' => 'solid', 'color' => '#000000']]],
                    ],
                ],
                [
                    'key' => 'mark',
                    'type' => 'shape',
                    'properties' => [['name' => 'inlineSvg', 'value' => '<svg viewBox="0 0 10 10"><path d="M0 0h10v10H0z"/></svg>']],
                ],
            ],
            'groups' => [['id' => 'visuals', 'visible' => true]],
        ];

        SnapsbrewServer::actingAs($user)->tool(PutEditorDocumentTool::class, [
            'template_id' => $template->uuid,
            'revision' => 0,
            'document' => $document,
        ])->assertOk()->assertSee('"revision": 1');

        $template->refresh();
        $this->assertSame($document['layers'], json_decode($template->layers, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($document['groups'], json_decode($template->groups, true, 512, JSON_THROW_ON_ERROR));

        $source = DB::transaction(function () use ($template) {
            $versioner = app(SnapSourceVersioner::class);

            return $versioner->sourceForLockedTemplate($versioner->lock($template));
        });
        $this->assertSame($template->layers, $source->layers);
        $this->assertSame($template->groups, $source->groups);

        SnapsbrewServer::actingAs($user)->tool(PutEditorDocumentTool::class, [
            'template_id' => $template->uuid,
            'revision' => 0,
            'document' => ['version' => 2, 'layers' => [['key' => 'stale', 'properties' => []]]],
        ])->assertHasErrors()->assertSee('editor_document_conflict');

        $template->refresh();
        $this->assertSame($document['layers'], json_decode($template->layers, true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame($document['groups'], json_decode($template->groups, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_put_editor_document_rejects_unsafe_or_invalid_layers_without_changing_the_draft(): void
    {
        $user = $this->makeUser();
        $template = $this->template($user);
        $template->forceFill(['groups' => '[{"id":"existing","visible":true}]'])->save();
        $original = [
            'document' => $template->editor_document,
            'revision' => $template->editor_revision,
            'layers' => $template->layers,
            'groups' => $template->groups,
        ];

        $cases = [
            [
                [[
                    'key' => 'unsafe', 'properties' => [
                        ['name' => 'backgroundImage', 'value' => 'http://169.254.169.254/latest/meta-data'],
                    ],
                ],
                ],
                'unsafe address',
            ],
            [
                [['key' => 'malformed']],
                'must include a `properties` array',
            ],
            [
                [
                    ['key' => 'duplicate', 'properties' => []],
                    ['key' => 'duplicate', 'properties' => []],
                ],
                'reuses the key `duplicate`',
            ],
        ];

        foreach ($cases as [$layers, $message]) {
            SnapsbrewServer::actingAs($user)->tool(PutEditorDocumentTool::class, [
                'template_id' => $template->uuid,
                'revision' => 0,
                'document' => ['version' => 2, 'layers' => $layers],
            ])->assertHasErrors([$message]);

            $template->refresh();
            $this->assertSame($original['revision'], $template->editor_revision);
            $this->assertSame($original['document'], $template->editor_document);
            $this->assertSame($original['layers'], $template->layers);
            $this->assertSame($original['groups'], $template->groups);
        }
    }

    public function test_put_editor_document_rejects_an_invalid_group_id_without_changing_the_draft(): void
    {
        $user = $this->makeUser();
        $template = $this->template($user);
        $original = [
            'document' => $template->editor_document,
            'revision' => $template->editor_revision,
            'layers' => $template->layers,
            'groups' => $template->groups,
        ];

        $cases = [
            [['layers' => [], 'groups' => [['id' => ['not-a-string']]]], 'groups.0.id'],
            [['layers' => [['key' => 'bad', 'groupId' => ['not-a-string'], 'properties' => []]], 'groups' => []], 'layers.0.groupId'],
            [['layers' => [['key' => 'missing', 'groupId' => 'unknown', 'properties' => []]], 'groups' => []], 'layers.0.groupId'],
        ];

        foreach ($cases as [$document, $message]) {
            SnapsbrewServer::actingAs($user)->tool(PutEditorDocumentTool::class, [
                'template_id' => $template->uuid,
                'revision' => 0,
                'document' => ['version' => 2] + $document,
            ])->assertHasErrors([$message]);

            $template->refresh();
            $this->assertSame($original['document'], $template->editor_document);
            $this->assertSame($original['revision'], $template->editor_revision);
            $this->assertSame($original['layers'], $template->layers);
            $this->assertSame($original['groups'], $template->groups);
        }
    }

    public function test_put_editor_document_changes_a_published_template_draft_only_until_publish(): void
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(PublishTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk();

        SnapsbrewServer::actingAs($user)->tool(PutEditorDocumentTool::class, [
            'template_id' => $template->uuid,
            'revision' => 0,
            'document' => ['version' => 2, 'layers' => [[
                'key' => 'headline', 'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'MCP draft']],
            ]]],
        ])->assertOk();

        $template->refresh();
        $source = DB::transaction(function () use ($template) {
            $versioner = app(SnapSourceVersioner::class);

            return $versioner->sourceForLockedTemplate($versioner->lock($template));
        });

        $this->assertSame('MCP draft', json_decode($template->layers, true, 512, JSON_THROW_ON_ERROR)[0]['properties'][0]['value']);
        $this->assertSame('Hi', json_decode($source->layers, true, 512, JSON_THROW_ON_ERROR)[0]['properties'][0]['value']);
    }

    public function test_preview_template_creates_no_api_log_and_no_snap()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(PreviewTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"preview": true')->assertSee('"format": "html"');

        $this->assertDatabaseCount('api_logs', 0);
        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_preview_snap_creates_no_api_log_and_no_snap()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(PreviewSnapTool::class, [
            'template_id' => $template->uuid,
            'variants' => [['layer' => 'headline', 'text' => 'Custom']],
        ])->assertOk()->assertDontSee('credits_charged')->assertSee('Custom');

        $this->assertDatabaseCount('api_logs', 0);
        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_create_snap_records_one_mcp_render()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk();

        Bus::assertDispatchedSync(ProcessSnap::class);
        $this->assertSame(1, ApiLog::where('feature', UsageRecorder::FEATURE_MCP)->where('renders', 1)->count());
    }

    public function test_create_snap_returns_dimensions_and_records_pdf_as_mcp()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, [
            'template_id' => $template->uuid,
            'type' => 'pdf',
        ])->assertOk()->assertSee('"width": 100')->assertSee('"height": 100');

        $this->assertSame(1, ApiLog::where('feature', UsageRecorder::FEATURE_MCP)
            ->where('type', 'pdf')->where('renders', 1)->count());
        $this->assertDatabaseHas('snaps', ['template_id' => $template->id, 'type' => 'pdf']);
    }

    public function test_preview_snap_flags_unknown_layer_keys()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(PreviewSnapTool::class, [
            'template_id' => $template->uuid,
            'variants' => [['layer' => 'does_not_exist', 'text' => 'X']],
        ])->assertOk()
            ->assertSee('does_not_exist')
            ->assertSee('WARNING');
    }

    public function test_create_snap_flags_unknown_layer_keys()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, [
            'template_id' => $template->uuid,
            'variants' => [['layer' => 'bogus_key', 'text' => 'X']],
        ])->assertOk()->assertSee('bogus_key');
    }

    public function test_create_snap_rejects_internal_image_url_in_variants()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(CreateSnapTool::class, [
            'template_id' => $template->uuid,
            'variants' => [['layer' => 'headline', 'image_url' => 'http://10.0.0.1/x.png']],
        ])->assertHasErrors();

        $this->assertDatabaseCount('snaps', 0);
    }

    public function test_create_template_succeeds_for_admin()
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        SnapsbrewServer::actingAs($admin)->tool(CreateTemplateTool::class, [
            'title' => 'X',
            'width' => 800,
            'height' => 600,
            'layers' => [],
        ])->assertOk()->assertSee('X');

        $this->assertDatabaseHas('templates', [
            'title' => 'X',
            'user_id' => $admin->id,
        ]);
    }

    public function test_create_template_succeeds_for_customer()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Fresh',
            'width' => 800,
            'height' => 600,
            'layers' => [],
        ])->assertOk()->assertSee('Fresh');

        $this->assertDatabaseHas('templates', [
            'title' => 'Fresh',
            'company_id' => $user->company_id,
        ]);
    }

    public function test_create_template_rejects_internal_background_image_in_layers()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Sneaky',
            'width' => 800,
            'height' => 600,
            'layers' => [[
                'key' => 'bg',
                'type' => 'image',
                'properties' => [
                    ['name' => 'backgroundImage', 'value' => 'http://169.254.169.254/latest/meta-data/'],
                ],
            ]],
        ])->assertHasErrors();

        $this->assertDatabaseMissing('templates', ['title' => 'Sneaky']);
    }

    public function test_create_template_rejects_oversized_dimensions()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Huge',
            'width' => 99999,
            'height' => 99999,
            'layers' => [],
        ])->assertHasErrors();

        $this->assertDatabaseMissing('templates', ['title' => 'Huge']);
    }

    public function test_edit_template_succeeds_for_non_owner_company_member()
    {
        // Templates are company-shared: any member of the owning company may
        // edit, not only the original creator.
        $owner = $this->makeUser();
        $template = $this->template($owner);
        $template->update(['title' => 'Owned']);

        $other = User::factory()->create(['company_id' => $owner->company_id]);

        SnapsbrewServer::actingAs($other)->tool(EditTemplateTool::class, [
            'template_id' => $template->uuid,
            'title' => 'Edited by teammate',
        ])->assertOk()->assertSee('Edited by teammate');

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'title' => 'Edited by teammate']);
    }

    public function test_edit_template_errors_for_other_company()
    {
        $user = $this->makeUser();
        $other = Template::factory()->create();

        SnapsbrewServer::actingAs($user)->tool(EditTemplateTool::class, [
            'template_id' => $other->uuid,
            'title' => 'Nope',
        ])->assertHasErrors();
    }

    public function test_edit_template_succeeds_for_owner()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(EditTemplateTool::class, [
            'template_id' => $template->uuid,
            'title' => 'Renamed',
        ])->assertOk()->assertSee('Renamed');

        $this->assertDatabaseHas('templates', ['id' => $template->id, 'title' => 'Renamed']);
    }

    public function test_publish_tool_publishes_the_draft()
    {
        Bus::fake(); // skip the queued version-preview render
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(PublishTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"published_version": 1');

        $this->assertNotNull($template->fresh()->published_version_id);
        $this->assertDatabaseHas('template_versions', [
            'template_id' => $template->id, 'version_number' => 1, 'published' => true,
        ]);
    }

    /**
     * The tool answers `uuid` beside the deprecated `id`.
     *
     * `POST /v1/templates/{templateUuid}/publish` answers both keys with the
     * same UUID. A control or a field on one surface and not on its twin reads
     * as a decision and is an oversight, so the two surfaces stay level.
     */
    public function test_publish_tool_answers_uuid_beside_the_deprecated_id()
    {
        Bus::fake(); // skip the queued version-preview render
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(PublishTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()
            ->assertSee('"id": "'.$template->uuid.'"')
            ->assertSee('"uuid": "'.$template->uuid.'"');
    }

    public function test_publish_tool_is_safe_to_repeat_for_an_unchanged_draft()
    {
        // The fake records every dispatch call, which is the only thing this
        // test counts. It does not stand in for the queue behavior.
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(PublishTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"published_version": 1');

        // A retried call must answer with the version which is already live.
        SnapsbrewServer::actingAs($user)->tool(PublishTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"published_version": 1');

        $this->assertSame(1, TemplateVersion::where('template_id', $template->id)->count());
        Bus::assertDispatchedTimes(GenerateTemplateVersionPreview::class, 1);
    }

    public function test_publish_tool_errors_for_other_company()
    {
        Bus::fake();
        $user = $this->makeUser();
        $other = Template::factory()->create();

        SnapsbrewServer::actingAs($user)->tool(PublishTemplateTool::class, [
            'template_id' => $other->uuid,
        ])->assertHasErrors();
    }

    public function test_edit_writes_draft_and_only_publish_makes_it_live()
    {
        Bus::fake();
        $user = $this->makeUser();
        $template = $this->template($user); // headline text = "Hi"

        // Publish the original so the render path renders the published version.
        SnapsbrewServer::actingAs($user)->tool(PublishTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk();

        // Edit the DRAFT — this must not change what the render path emits.
        SnapsbrewServer::actingAs($user)->tool(EditTemplateTool::class, [
            'template_id' => $template->uuid,
            'layers' => [[
                'key' => 'headline', 'type' => 'text',
                'properties' => [['name' => 'textContent', 'value' => 'BYE']],
            ]],
        ])->assertOk();

        // preview-template renders renderableLayers() = the published version.
        SnapsbrewServer::actingAs($user)->tool(PreviewTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('Hi')->assertDontSee('BYE');

        // get-template-details flags the pending draft so the agent knows to publish.
        SnapsbrewServer::actingAs($user)->tool(GetTemplateDetailsTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"has_unpublished_edits": true');

        // Publish the draft — now the render path emits the edit.
        SnapsbrewServer::actingAs($user)->tool(PublishTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"published_version": 2');

        SnapsbrewServer::actingAs($user)->tool(PreviewTemplateTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('BYE');
    }

    public function test_get_template_details_reports_no_unpublished_edits_when_never_published()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(GetTemplateDetailsTool::class, [
            'template_id' => $template->uuid,
        ])->assertOk()->assertSee('"has_unpublished_edits": false');
    }

    public function test_describe_layers_returns_shape_and_vocabulary()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(DescribeLayersTool::class)
            ->assertOk()
            ->assertSee('layer_shape')
            ->assertSee('property_names')
            ->assertSee('textContent')
            ->assertSee('backgroundImage')
            ->assertSee('example');
    }

    public function test_design_guide_returns_guidance()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(DesignGuideTool::class)
            ->assertOk()
            ->assertSee('canvas_presets')
            ->assertSee('layer_order')
            ->assertSee('recipes')
            ->assertSee('preflight_checklist');
    }

    public function test_platform_guide_lists_every_platform_when_none_is_named()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PlatformGuideTool::class)
            ->assertOk()
            ->assertSee('instagram')
            ->assertSee('start_here')
            ->assertSee('specs_reviewed');
    }

    public function test_platform_guide_returns_one_platforms_rules()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PlatformGuideTool::class, ['platform' => 'instagram'])
            ->assertOk()
            ->assertSee('ui_safe_zones')
            ->assertSee('instagram_story')
            ->assertSee('cropping')
            ->assertDontSee('pinterest_pin');
    }

    public function test_platform_guide_understands_the_names_people_type()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PlatformGuideTool::class, ['platform' => 'insta'])
            ->assertOk()
            ->assertSee('instagram_post');

        SnapsbrewServer::actingAs($user)->tool(PlatformGuideTool::class, ['platform' => 'twitter'])
            ->assertOk()
            ->assertSee('twitter_card');
    }

    public function test_platform_guide_reports_an_unknown_platform()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(PlatformGuideTool::class, ['platform' => 'myspace'])
            ->assertHasErrors();
    }

    public function test_image_purpose_guide_lists_every_purpose_when_none_is_named()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ImagePurposeGuideTool::class)
            ->assertOk()
            ->assertSee('link_preview')
            ->assertSee('carousel')
            ->assertSee('video_thumbnail');
    }

    public function test_image_purpose_guide_returns_the_meta_tags_for_a_link_preview()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ImagePurposeGuideTool::class, ['purpose' => 'meta tag'])
            ->assertOk()
            ->assertSee('og:image:width')
            ->assertSee('summary_large_image')
            ->assertSee('open_graph')
            // The agent copies this snippet into a page head. Escaped slashes
            // would make it wrong, so the tool encodes with JSON_UNESCAPED_SLASHES.
            ->assertSee('https://example.com/snap.png');
    }

    public function test_image_purpose_guide_returns_a_recipe_and_a_checklist()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ImagePurposeGuideTool::class, ['purpose' => 'gallery'])
            ->assertOk()
            ->assertSee('layer_recipe')
            ->assertSee('checklist')
            ->assertSee('aspect ratio');
    }

    public function test_image_purpose_guide_reports_an_unknown_purpose()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ImagePurposeGuideTool::class, ['purpose' => 'billboard'])
            ->assertHasErrors();
    }

    public function test_list_template_sizes_returns_the_preset_catalog()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ListTemplateSizesTool::class)
            ->assertOk()
            ->assertSee('instagram_story')
            ->assertSee('youtube_thumbnail')
            ->assertSee('safe_margin_px');
    }

    public function test_list_template_sizes_filters_by_platform()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ListTemplateSizesTool::class, ['platform' => 'pinterest'])
            ->assertOk()
            ->assertSee('pinterest_pin')
            ->assertDontSee('youtube_thumbnail');
    }

    public function test_list_template_sizes_reports_an_unknown_platform()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(ListTemplateSizesTool::class, ['platform' => 'myspace'])
            ->assertHasErrors();
    }

    public function test_create_template_accepts_a_size_preset_instead_of_dimensions()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Story',
            'size' => 'instagram_story',
            'layers' => [],
        ])->assertOk()->assertSee('instagram_story');

        $this->assertDatabaseHas('templates', [
            'title' => 'Story',
            'size' => 'instagram_story',
            'width' => 1080,
            'height' => 1920,
        ]);
    }

    public function test_create_template_rejects_an_unknown_size_preset()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Nope',
            'size' => 'myspace_banner',
            'layers' => [],
        ])->assertHasErrors();

        $this->assertDatabaseMissing('templates', ['title' => 'Nope']);
    }

    public function test_create_template_requires_a_size_or_dimensions()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Sizeless',
            'layers' => [],
        ])->assertHasErrors();

        $this->assertDatabaseMissing('templates', ['title' => 'Sizeless']);
    }

    public function test_create_template_stores_the_preset_matching_explicit_dimensions()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Thumb',
            'width' => 1280,
            'height' => 720,
            'layers' => [],
        ])->assertOk();

        $this->assertDatabaseHas('templates', [
            'title' => 'Thumb',
            'size' => 'youtube_thumbnail',
        ]);
    }

    public function test_edit_template_resizes_with_a_size_preset()
    {
        $user = $this->makeUser();
        $template = $this->template($user);

        SnapsbrewServer::actingAs($user)->tool(EditTemplateTool::class, [
            'template_id' => $template->uuid,
            'size' => 'open_graph',
        ])->assertOk()->assertSee('open_graph');

        $template->refresh();
        $this->assertEquals(1200, $template->width);
        $this->assertEquals(630, $template->height);
        $this->assertEquals('open_graph', $template->size);
    }

    public function test_create_template_rejects_layer_without_key()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Keyless',
            'width' => 800,
            'height' => 600,
            'layers' => [[
                'properties' => [
                    ['name' => 'textContent', 'value' => 'Hi'],
                ],
            ]],
        ])->assertHasErrors();

        $this->assertDatabaseMissing('templates', ['title' => 'Keyless']);
    }

    public function test_create_template_rejects_property_without_name()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'BadProp',
            'width' => 800,
            'height' => 600,
            'layers' => [[
                'key' => 'headline',
                'properties' => [
                    ['value' => 'Hi'],
                ],
            ]],
        ])->assertHasErrors();

        $this->assertDatabaseMissing('templates', ['title' => 'BadProp']);
    }

    public function test_create_template_rejects_duplicate_layer_keys()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Dupe',
            'width' => 800,
            'height' => 600,
            'layers' => [
                ['key' => 'headline', 'properties' => []],
                ['key' => 'headline', 'properties' => []],
            ],
        ])->assertHasErrors();

        $this->assertDatabaseMissing('templates', ['title' => 'Dupe']);
    }

    public function test_create_template_accepts_well_formed_layers()
    {
        $user = $this->makeUser();

        SnapsbrewServer::actingAs($user)->tool(CreateTemplateTool::class, [
            'title' => 'Valid',
            'width' => 800,
            'height' => 600,
            'layers' => [
                ['key' => 'headline', 'properties' => [
                    ['name' => 'textContent', 'value' => 'Hi'],
                ]],
            ],
        ])->assertOk()->assertSee('Valid');

        $this->assertDatabaseHas('templates', ['title' => 'Valid']);
    }
}

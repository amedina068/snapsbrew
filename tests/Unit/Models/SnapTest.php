<?php

namespace Tests\Unit\Models;

use App\Contracts\SnapRenderer;
use App\Models\Company;
use App\Models\Form;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeRenderer;
use Tests\TestCase;

class SnapTest extends TestCase
{
    use RefreshDatabase;

    public function test_snap_belongs_to_template()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
        ]);

        $this->assertInstanceOf(Template::class, $snap->template);
        $this->assertEquals($template->id, $snap->template->id);
    }

    public function test_snap_belongs_to_company()
    {
        $company = Company::factory()->create();
        $template = Template::factory()->create(['company_id' => $company->id]);
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $company->id,
        ]);

        $this->assertInstanceOf(Company::class, $snap->company);
    }

    public function test_snap_belongs_to_import()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $import = SnapImport::factory()->create([
            'template_id' => $template->id,
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'snap_import_id' => $import->id,
        ]);

        $this->assertInstanceOf(SnapImport::class, $snap->import);
    }

    public function test_snap_belongs_to_form()
    {
        $user = User::factory()->create();
        $template = Template::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
        ]);
        $form = Form::factory()->create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'formable_id' => $template->id,
            'formable_type' => Template::class,
        ]);
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $user->company_id,
            'form_id' => $form->id,
        ]);

        $this->assertInstanceOf(Form::class, $snap->form);
    }

    public function test_is_complete()
    {
        $snap = Snap::factory()->completed()->create();
        $this->assertTrue($snap->isComplete());

        $pendingSnap = Snap::factory()->create();
        $this->assertFalse($pendingSnap->isComplete());
    }

    public function test_status_constants()
    {
        $this->assertEquals('pending', Snap::STATUS_PENDING);
        $this->assertEquals('processing', Snap::STATUS_PROCESSING);
        $this->assertEquals('completed', Snap::STATUS_COMPLETED);
        $this->assertEquals('failed', Snap::STATUS_FAILED);
        $this->assertEquals('canceled', Snap::STATUS_CANCELED);
    }

    public function test_type_constants()
    {
        $this->assertEquals('image', Snap::TYPE_IMAGE);
        $this->assertEquals('pdf', Snap::TYPE_PDF);
    }

    public function test_is_async_is_cast_to_boolean()
    {
        $snap = Snap::factory()->async()->create();

        $this->assertTrue($snap->is_async);
        $this->assertIsBool($snap->is_async);
    }

    public function test_completed_at_is_cast_to_datetime()
    {
        $snap = Snap::factory()->completed()->create();

        $this->assertInstanceOf(Carbon::class, $snap->completed_at);
    }

    public function test_metadata_is_cast_to_array()
    {
        $snap = Snap::factory()->create([
            'metadata' => ['key' => 'value'],
        ]);

        $this->assertIsArray($snap->fresh()->metadata);
        $this->assertEquals('value', $snap->fresh()->metadata['key']);
    }

    public function test_snap_has_uuid()
    {
        $snap = Snap::factory()->create();

        $this->assertNotNull($snap->uuid);
    }

    public function test_render_image_base64_returns_base64_without_persisting_media()
    {
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        $template = Template::factory()->create(['width' => '100', 'height' => '100']);
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $template->company_id,
        ]);

        $base64 = $snap->renderImageBase64();

        $this->assertEquals(FakeRenderer::FAKE_PNG, $base64);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_get_image_uses_format_extension_and_mime()
    {
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        $template = Template::factory()->create(['width' => '100', 'height' => '100']);
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $template->company_id,
            'format' => 'webp',
        ]);

        $snap->getImage();

        $media = $snap->getFirstMedia('image');
        $this->assertStringEndsWith('.webp', $media->file_name);
        $this->assertSame('image/webp', $media->getCustomProperty('mime-type'));
    }

    public function test_get_image_returns_url_when_document_media_already_loaded()
    {
        // renderDocument() reads html_head/html_content, which loads and caches
        // the `media` relation BEFORE the image is added. Regression guard: the
        // returned URL must reflect the freshly-added image, not the stale cache.
        $this->app->bind(SnapRenderer::class, FakeRenderer::class);

        $template = Template::factory()->create(['width' => '100', 'height' => '100']);
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $template->company_id,
            'format' => 'png',
        ]);

        $snap->addHead('<style>body{}</style>');
        $snap->addContent('<div>hi</div>');

        $url = $snap->getImage();

        $this->assertNotEmpty($url);
        $this->assertSame($url, $snap->imageUrl);
    }

    public function test_render_document_wraps_persisted_head_and_content()
    {
        $template = Template::factory()->create();
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $template->company_id,
        ]);

        $snap->addHead('<title>HEAD_MARKER</title>');
        $snap->addContent('<div>CONTENT_MARKER</div>');

        $document = $snap->renderDocument();

        $this->assertStringContainsString('<!DOCTYPE html>', $document);
        $this->assertStringContainsString('HEAD_MARKER', $document);
        $this->assertStringContainsString('CONTENT_MARKER', $document);
    }

    public function test_get_image_threads_format_and_transparent_to_renderer()
    {
        $fake = new FakeRenderer;
        $this->app->instance(SnapRenderer::class, $fake);

        $template = Template::factory()->create(['width' => '100', 'height' => '100']);
        $snap = Snap::factory()->create([
            'template_id' => $template->id,
            'company_id' => $template->company_id,
            'format' => 'png',
            'transparent' => true,
        ]);

        $snap->getImage();

        $this->assertSame('png', $fake->screenshotCalls[0]['format']);
        $this->assertTrue($fake->screenshotCalls[0]['transparent']);
    }

    public function test_get_pdf_url_attribute_returns_media_url()
    {
        $snap = Snap::factory()->create();

        // No media yet → empty string (Spatie's getFirstMediaUrl default),
        // proving the accessor resolves instead of hitting an undefined property.
        $this->assertSame('', $snap->pdf_url);
    }

    public function test_factory_states()
    {
        $completed = Snap::factory()->completed()->create();
        $this->assertEquals(Snap::STATUS_COMPLETED, $completed->status);

        $failed = Snap::factory()->failed()->create();
        $this->assertEquals(Snap::STATUS_FAILED, $failed->status);
        $this->assertNotNull($failed->last_error);

        $processing = Snap::factory()->processing()->create();
        $this->assertEquals(Snap::STATUS_PROCESSING, $processing->status);

        $canceled = Snap::factory()->canceled()->create();
        $this->assertEquals(Snap::STATUS_CANCELED, $canceled->status);

        $pdf = Snap::factory()->pdf()->create();
        $this->assertEquals(Snap::TYPE_PDF, $pdf->type);
    }
}

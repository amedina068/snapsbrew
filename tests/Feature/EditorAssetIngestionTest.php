<?php

namespace Tests\Feature;

use App\Editor\TemplateDocumentAssetNormalizer;
use App\Models\Company;
use App\Models\EditorAsset;
use App\Services\EditorAssetIngestion;
use App\ValueObjects\EditorAssetHttpResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakeEditorAssetTransport;
use Tests\Support\FakeEditorAssetUrlResolver;
use Tests\TestCase;

class EditorAssetIngestionTest extends TestCase
{
    use RefreshDatabase;

    private string $onePixelPng;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $this->onePixelPng = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        imagedestroy($image);
    }

    public function test_data_uri_is_canonicalized_and_deduplicated_per_company(): void
    {
        $company = Company::factory()->create();
        $ingestion = app(EditorAssetIngestion::class);

        $first = $ingestion->ingestDataUri($company->id, $this->onePixelPng);
        $second = $ingestion->ingestDataUri($company->id, $this->onePixelPng);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('image/png', $first->mime_type);
        $this->assertSame(1, $first->width);
        $this->assertSame(1, $first->height);
        $this->assertTrue($first->hasMedia('source'));
        $this->assertSame('local', $first->getFirstMedia('source')->disk);
    }

    public function test_svg_and_non_base64_data_uris_are_rejected(): void
    {
        $company = Company::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('asset_type_rejected');

        app(EditorAssetIngestion::class)->ingestDataUri(
            $company->id,
            'data:image/svg+xml,%3Csvg%2F%3E'
        );
    }

    public function test_remote_private_address_is_rejected_before_fetching(): void
    {
        $company = Company::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('asset_url_rejected');

        app(EditorAssetIngestion::class)->ingestUrl($company->id, 'http://127.0.0.1/image.png');
    }

    public function test_v2_document_replaces_data_uri_with_tenant_asset_id(): void
    {
        $company = Company::factory()->create();

        $normalized = app(TemplateDocumentAssetNormalizer::class)->normalize($company->id, [
            'version' => 2,
            'layers' => [[
                'id' => 'hero',
                'type' => 'image',
                'source' => ['url' => $this->onePixelPng],
            ]],
        ]);

        $this->assertArrayNotHasKey('url', $normalized['document']['layers'][0]['source']);
        $this->assertCount(1, $normalized['asset_ids']);
        $this->assertSame($normalized['asset_ids'][0], $normalized['document']['layers'][0]['source']['asset_id']);
    }

    public function test_invalid_signature_and_byte_limit_are_rejected(): void
    {
        $company = Company::factory()->create();
        $ingestion = app(EditorAssetIngestion::class);

        try {
            $ingestion->ingestBytes($company->id, str_repeat('x', EditorAsset::MAX_BYTES + 1));
            $this->fail('Expected byte limit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('asset_limit_exceeded', $exception->getMessage());
        }

        try {
            $ingestion->ingestBytes($company->id, "\x89PNG\r\n\x1A\nnot-an-image");
            $this->fail('Expected signature/type failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('asset_type_rejected', $exception->getMessage());
        }
    }

    public function test_pixel_dimension_limit_is_rejected_before_persistence(): void
    {
        $company = Company::factory()->create();
        $image = imagecreatetruecolor(6001, 1);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('asset_limit_exceeded');

        app(EditorAssetIngestion::class)->ingestBytes($company->id, $bytes);
    }

    public function test_remote_redirect_is_revalidated_before_a_second_request(): void
    {
        $company = Company::factory()->create();
        $transport = new FakeEditorAssetTransport([
            new EditorAssetHttpResponse(302, ['location' => 'http://127.0.0.1/private.png'], ''),
        ]);
        $resolver = new FakeEditorAssetUrlResolver([
            ['host' => 'images.example.test', 'port' => 80, 'ip' => '93.184.216.34'],
            new RuntimeException('asset_url_rejected'),
        ]);

        try {
            (new EditorAssetIngestion($transport, $resolver))->ingestUrl($company->id, 'http://images.example.test/a.png');
            $this->fail('Expected redirect destination rejection.');
        } catch (RuntimeException $exception) {
            $this->assertSame('asset_url_rejected', $exception->getMessage());
        }

        $this->assertCount(1, $transport->requests);
    }

    public function test_remote_response_is_pinned_and_changed_bytes_create_distinct_assets(): void
    {
        $company = Company::factory()->create();
        $red = $this->pngBytes(255, 0, 0);
        $blue = $this->pngBytes(0, 0, 255);
        $transport = new FakeEditorAssetTransport([
            new EditorAssetHttpResponse(200, [], $red),
            new EditorAssetHttpResponse(200, [], $blue),
        ]);
        $resolver = new FakeEditorAssetUrlResolver([
            ['host' => 'images.example.test', 'port' => 443, 'ip' => '93.184.216.34'],
            ['host' => 'images.example.test', 'port' => 443, 'ip' => '93.184.216.34'],
        ]);
        $ingestion = new EditorAssetIngestion($transport, $resolver);

        $first = $ingestion->ingestUrl($company->id, 'https://images.example.test/logo.png');
        $second = $ingestion->ingestUrl($company->id, 'https://images.example.test/logo.png');

        $this->assertNotSame($first->sha256, $second->sha256);
        $this->assertCount(2, $transport->requests);
        $this->assertSame('93.184.216.34', $transport->requests[0]['destination']['ip']);
        $this->assertTrue($first->hasMedia('source'));
        $this->assertTrue($second->hasMedia('source'));
    }

    public function test_remote_overflow_and_timeout_reject_without_persistence(): void
    {
        $company = Company::factory()->create();
        foreach ([
            new EditorAssetHttpResponse(200, [], '', overflow: true),
            new EditorAssetHttpResponse(200, [], '', timedOut: true),
        ] as $response) {
            try {
                (new EditorAssetIngestion(
                    new FakeEditorAssetTransport([$response]),
                    new FakeEditorAssetUrlResolver([['host' => 'images.example.test', 'port' => 443, 'ip' => '93.184.216.34']]),
                ))->ingestUrl($company->id, 'https://images.example.test/a.png');
                $this->fail('Expected remote failure.');
            } catch (RuntimeException $exception) {
                $this->assertContains($exception->getMessage(), ['asset_limit_exceeded', 'asset_url_rejected']);
            }
        }
        $this->assertDatabaseCount('editor_assets', 0);
    }

    private function pngBytes(int $red, int $green, int $blue): string
    {
        $image = imagecreatetruecolor(1, 1);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}

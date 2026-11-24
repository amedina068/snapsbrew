<?php

namespace Tests\Unit;

use App\Support\ApiDocs\OpenApiGenerator;
use Tests\TestCase;

/**
 * The `operationId` set of the generated OpenAPI document is a public contract.
 *
 * A separate repository generates client code from these ids, and the generated
 * method name follows the id. A rename is therefore a breaking change for a
 * consumer this repository cannot see, and nothing here reports the break.
 *
 * If this test fails, an id changed. A client in another repository will break
 * on the next generation. The correct answer is almost always to restore the
 * old id. Add a line here only when the change adds an endpoint, and remove a
 * line only when the endpoint itself goes.
 */
class OpenApiOperationIdsTest extends TestCase
{
    /**
     * Every `operationId` the document carries, sorted.
     *
     * @var array<int, string>
     */
    private const OPERATION_IDS = [
        'auth_show',
        'editor_documents_apply',
        'editor_documents_preflight',
        'editor_documents_show',
        'editor_documents_update',
        'embedded_forms_store',
        'guides_design',
        'guides_editor_document',
        'guides_layers',
        'guides_platform',
        'guides_platforms',
        'guides_purpose',
        'guides_purposes',
        'guides_variant_properties',
        'media_index',
        'media_store',
        'preview_snap',
        'preview_template',
        'screenshots_index',
        'screenshots_show',
        'screenshots_store',
        'signed_urls_image',
        'snap_batches_cancel',
        'snap_batches_show',
        'snap_batches_store',
        'snaps_store',
        'tags_index',
        'tags_store',
        'tags_template_store',
        'templates_destroy',
        'templates_index',
        'templates_publish',
        'templates_show',
        'templates_sizes',
        'templates_store',
        'templates_update',
    ];

    public function test_the_generated_document_carries_exactly_the_published_operation_ids()
    {
        $this->assertSame(self::OPERATION_IDS, $this->generatedOperationIds());
    }

    /**
     * An id must be unique. OpenAPI requires it, and a generator that meets two
     * of the same id names one client method twice or drops one endpoint.
     */
    public function test_no_operation_id_is_used_twice()
    {
        $ids = $this->generatedOperationIds();

        $this->assertSame($ids, array_values(array_unique($ids)), 'Two endpoints share one operationId.');
    }

    /**
     * Read the ids out of the generated document, never out of `ApiSpec`. The
     * document is what a client downloads, and `OpenApiGenerator` rewrites the
     * id on the way out.
     *
     * @return array<int, string>
     */
    private function generatedOperationIds(): array
    {
        $out = [];

        foreach ((new OpenApiGenerator)->toArray()['paths'] as $operations) {
            foreach ($operations as $operation) {
                $out[] = $operation['operationId'];
            }
        }

        sort($out);

        return $out;
    }
}

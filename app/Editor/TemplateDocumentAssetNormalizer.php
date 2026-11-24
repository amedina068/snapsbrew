<?php

namespace App\Editor;

use App\Models\EditorAsset;
use App\Services\EditorAssetIngestion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Converts the v2 document's image input into an immutable tenant asset id.
 * The document never retains an external URL after this boundary.
 */
class TemplateDocumentAssetNormalizer
{
    public function __construct(private EditorAssetIngestion $ingestion) {}

    /** @return array{document: array, asset_ids: array<int, string>} */
    public function normalize(int $companyId, array $document): array
    {
        $assetIds = [];

        foreach ($document['layers'] ?? [] as $index => $layer) {
            if (! is_array($layer) || ($layer['type'] ?? null) !== 'image') {
                continue;
            }

            $source = $layer['source'] ?? null;
            if (! is_array($source)) {
                continue;
            }

            if (isset($source['asset_id']) && is_string($source['asset_id'])) {
                $asset = EditorAsset::query()
                    ->where('company_id', $companyId)
                    ->where('uuid', $source['asset_id'])
                    ->firstOrFail();
            } elseif (isset($source['url']) && is_string($source['url'])) {
                $asset = str_starts_with($source['url'], 'data:')
                    ? $this->ingestion->ingestDataUri($companyId, $source['url'])
                    : $this->ingestion->ingestUrl($companyId, $source['url']);
            } else {
                throw new ModelNotFoundException('An image source needs a tenant asset_id or an ingestible URL.');
            }

            $document['layers'][$index]['source'] = ['asset_id' => $asset->uuid];
            $assetIds[] = $asset->uuid;
        }

        return ['document' => $document, 'asset_ids' => array_values(array_unique($assetIds))];
    }
}

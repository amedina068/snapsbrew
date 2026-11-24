<?php

namespace App\Services;

use App\Models\EditorAsset;
use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreativePackageSourcePreflight
{
    public function __construct(private readonly EditorPreflight $editorPreflight) {}

    /**
     * @param  array<int, array{client_creative_id: string, template_id: string, template_version: int}>  $items
     * @return array<int, TemplateVersion>
     */
    public function preflight(int $companyId, array $items): array
    {
        $templates = Template::query()
            ->where('company_id', $companyId)
            ->whereIn('uuid', array_column($items, 'template_id'))
            ->get(['id', 'uuid', 'width', 'height'])
            ->keyBy('uuid');
        $versions = $this->versions($templates, $items);
        $assetIds = $versions->flatMap(fn (TemplateVersion $version) => $this->assetIds($version->editor_document))
            ->unique()->values();
        $assets = $assetIds->isEmpty() ? collect() : EditorAsset::query()
            ->where('company_id', $companyId)->whereIn('uuid', $assetIds)->get(['id', 'uuid'])->keyBy('uuid');
        $links = $this->assetLinks($versions, $assets);

        $resolved = [];
        $details = [];
        foreach ($items as $index => $item) {
            $template = $templates->get($item['template_id']);
            $version = $template ? $versions->get($this->versionKey($template->id, $item['template_version'])) : null;
            if (! $version) {
                $details[] = $this->detail($index, $item['client_creative_id'], 'creative_package_source_not_found');

                continue;
            }

            $issues = $this->issues($version, $template, $assets, $links);
            if ($issues !== []) {
                $details[] = $this->detail($index, $item['client_creative_id'], 'creative_package_source_invalid', $issues);

                continue;
            }
            $resolved[$index] = $version;
        }

        if ($details !== []) {
            $code = collect($details)->contains('code', 'creative_package_source_invalid')
                ? 'creative_package_source_invalid'
                : 'creative_package_source_not_found';
            throw new CreativePackageSourcePreflightException($code, $details);
        }

        return $resolved;
    }

    /** @param Collection<string, Template> $templates
     * @param  array<int, array{template_id: string, template_version: int}>  $items
     * @return Collection<string, TemplateVersion>
     */
    private function versions(Collection $templates, array $items): Collection
    {
        if ($templates->isEmpty()) {
            return collect();
        }

        return TemplateVersion::query()->where(function ($query) use ($templates, $items): void {
            foreach ($items as $item) {
                $template = $templates->get($item['template_id']);
                if ($template) {
                    $query->orWhere(fn ($pair) => $pair->where('template_id', $template->id)
                        ->where('version_number', $item['template_version']));
                }
            }
        })->get()->keyBy(fn (TemplateVersion $version) => $this->versionKey($version->template_id, $version->version_number));
    }

    /** @param Collection<string, TemplateVersion> $versions
     * @param  Collection<string, EditorAsset>  $assets
     * @return array<int, array<int, true>>
     */
    private function assetLinks(Collection $versions, Collection $assets): array
    {
        if ($versions->isEmpty() || $assets->isEmpty()) {
            return [];
        }

        return DB::table('template_version_assets')
            ->whereIn('template_version_id', $versions->pluck('id'))
            ->whereIn('editor_asset_id', $assets->pluck('id'))
            ->get(['template_version_id', 'editor_asset_id'])
            ->groupBy('template_version_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [(int) $row->editor_asset_id => true])->all())
            ->all();
    }

    /** @param Collection<string, EditorAsset> $assets
     * @param  array<int, array<int, true>>  $links
     * @return array<int, string>
     */
    private function issues(TemplateVersion $version, Template $template, Collection $assets, array $links): array
    {
        $issues = [];
        $document = $version->editor_document;
        if (! $version->published) {
            $issues[] = 'source_unpublished';
        }
        if (! is_string($version->layers) || ! is_array($layers = json_decode($version->layers, true)) || ! array_is_list($layers) || count(array_filter($layers, 'is_array')) !== count($layers)) {
            $issues[] = 'legacy_snapshot_invalid';
        }
        $width = $version->width ?? $template->width;
        $height = $version->height ?? $template->height;
        if ($width < 1 || $height < 1 || $width > Template::MAX_DIMENSION || $height > Template::MAX_DIMENSION) {
            $issues[] = 'dimensions_invalid';
        }
        if ($document !== null && ! is_array($document)) {
            $issues[] = 'editor_document_invalid';
        }
        foreach ($this->editorPreflight->inspect(is_array($document) ? $document : null) as $issue) {
            $issues[] = $issue['code'];
        }
        foreach ($this->assetIds($document) as $assetId) {
            $asset = $assets->get($assetId);
            if (! $asset) {
                $issues[] = 'editor_asset_not_found';
            } elseif (! ($links[$version->id][$asset->id] ?? false)) {
                $issues[] = 'editor_asset_unlinked';
            }
        }

        return array_values(array_unique($issues));
    }

    /** @return array<int, string> */
    private function assetIds(mixed $document): array
    {
        return collect(is_array($document) ? $document['layers'] ?? [] : [])->map(fn (mixed $layer) => is_array($layer) ? ($layer['source']['asset_id'] ?? null) : null)
            ->filter(fn (mixed $id) => is_string($id))->values()->all();
    }

    /** @return array<string, mixed> */
    private function detail(int $index, string $clientCreativeId, string $code, array $issues = []): array
    {
        return array_filter(['item_index' => $index, 'client_creative_id' => $clientCreativeId, 'code' => $code, 'issues' => $issues],
            fn (mixed $value, string $key) => $key !== 'issues' || $value !== [], ARRAY_FILTER_USE_BOTH);
    }

    private function versionKey(int $templateId, int $versionNumber): string
    {
        return "{$templateId}:{$versionNumber}";
    }
}

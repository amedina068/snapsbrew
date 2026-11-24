<?php

namespace App\Console\Commands;

use App\Models\Template;
use App\Models\TemplateVersion;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Incrementally copies legacy layer/group payloads into the nullable v2
 * columns. It never changes the legacy columns or selects snap sources; S01c
 * owns that irreversible dispatch behaviour.
 */
class BackfillTemplateEditorDocuments extends Command
{
    protected $signature = 'editor:backfill-template-documents
                            {--table=templates : templates or template_versions}
                            {--after-id=0 : Resume after this primary key}
                            {--limit=500 : Maximum records to backfill}';

    protected $description = 'Backfill nullable editor v2 documents from legacy template snapshots.';

    public function handle(): int
    {
        $table = (string) $this->option('table');
        $model = match ($table) {
            'templates' => Template::class,
            'template_versions' => TemplateVersion::class,
            default => null,
        };

        $afterId = filter_var($this->option('after-id'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);

        if ($model === null || $afterId === false || $afterId < 0 || $limit === false || $limit < 1 || $limit > 5000) {
            $this->error('Use --table=templates|template_versions, a non-negative --after-id, and --limit between 1 and 5000.');

            return self::FAILURE;
        }

        /** @var Collection<int, Model> $records */
        $records = $model::query()
            ->whereNull('editor_document')
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $updated = 0;

        foreach ($records as $record) {
            $document = [
                'schema_version' => 2,
                'layers' => $this->decodeLegacyJson($record->getRawOriginal('layers')),
                'groups' => $this->decodeLegacyJson($record->getRawOriginal('groups')),
            ];

            $updated += $model::query()
                ->whereKey($record->id)
                ->whereNull('editor_document')
                ->update([
                    'editor_document' => json_encode($document, JSON_THROW_ON_ERROR),
                    'editor_schema_version' => 2,
                    'editor_revision' => 1,
                    'updated_at' => now(),
                ]);
        }

        $next = $records->last()?->id;
        $this->info(sprintf(
            'Backfilled %d %s record(s). Resume with --after-id=%s.',
            $updated,
            $table,
            $next ?? $afterId,
        ));

        return self::SUCCESS;
    }

    private function decodeLegacyJson(?string $value): array
    {
        $decoded = json_decode($value ?? '[]', true);

        return is_array($decoded) ? $decoded : [];
    }
}

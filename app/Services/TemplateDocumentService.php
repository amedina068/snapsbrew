<?php

namespace App\Services;

use App\Editor\EditorDocumentProjector;
use App\Exceptions\EditorDocumentConflict;
use App\Models\Template;
use App\Rules\SafeLayers;
use App\Rules\ValidLayers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Atomic, bounded v2 editor-document reads and compare-and-swap writes. */
class TemplateDocumentService
{
    public const MAX_BYTES = 262144;

    public const MAX_NODES = 1000;

    public const MAX_DEPTH = 12;

    public function __construct(private readonly EditorDocumentProjector $projector) {}

    /** @return array{document: array|null, revision: int} */
    public function get(Template $template): array
    {
        return [
            'document' => $template->editor_document,
            'revision' => (int) ($template->editor_revision ?? 0),
        ];
    }

    /** @return array{document: array, revision: int} */
    public function replace(Template $template, int $expectedRevision, array $document): array
    {
        // Every check of the request document runs before the lock.
        //
        // Cost: SafeLayers resolves the host of every absolute `backgroundImage`
        // value. SafeUrl::resolveIps() calls gethostbynamel() and
        // dns_get_record() with no timeout. Inside the transaction that blocking
        // lookup holds the `templates` row for the whole resolution, and
        // TemplateVersioning::publish() locks the same row. That service moves
        // its own slow step ahead of its own lock for this reason.
        //
        // Order: a request that is stale and malformed at once now answers 422,
        // not 409. A malformed document is malformed at every revision, so the
        // caller must repair the body. A 409 sends a compare-and-swap client
        // back to reread and retry, and the retry fails on the same body.
        //
        // The move is safe because all three methods read the request document
        // only. None of them reads the locked row.
        $this->assertBudget($document);
        $groupIds = $this->assertDirectGroupRules($document);
        $this->assertDirectLayerRules($document, $groupIds);

        // The lock covers the conflict check, the projection and the write.
        return DB::transaction(function () use ($template, $expectedRevision, $document) {
            $locked = Template::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $currentRevision = (int) ($locked->editor_revision ?? 0);

            if ($currentRevision !== $expectedRevision) {
                throw new EditorDocumentConflict($currentRevision, $locked->editor_document);
            }

            $nextRevision = $currentRevision + 1;
            $projection = $this->projector->project($document);
            $locked->forceFill([
                'editor_document' => $document,
                'editor_schema_version' => 2,
                'editor_revision' => $nextRevision,
                'layers' => $projection['layers'],
                'groups' => $projection['groups'],
            ])->save();

            return ['document' => $locked->editor_document, 'revision' => $nextRevision];
        });
    }

    /** @param array<string, mixed> $document */
    private function assertBudget(array $document): void
    {
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        $nodes = 0;

        $walk = function (mixed $value, int $depth) use (&$walk, &$nodes): void {
            if (++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
                throw ValidationException::withMessages(['document' => ['editor_document_budget_exceeded']]);
            }

            if (is_string($value) && mb_strlen($value) > 16384) {
                throw ValidationException::withMessages(['document' => ['editor_document_string_too_long']]);
            }

            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    if (! is_string($key) && ! is_int($key)) {
                        throw ValidationException::withMessages(['document' => ['editor_document_invalid_key']]);
                    }
                    $walk($item, $depth + 1);
                }
            }
        };

        if (strlen($json) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['document' => ['editor_document_too_large']]);
        }

        $walk($document, 0);
    }

    /** @param array<string, mixed> $document */
    private function assertDirectLayerRules(array $document, array $groupIds): void
    {
        Validator::validate(
            ['layers' => $document['layers'] ?? []],
            [
                'layers' => ['bail', 'present', 'array', new ValidLayers, new SafeLayers],
                'layers.*.groupId' => ['nullable', 'string', 'max:64', Rule::in($groupIds)],
            ],
        );
    }

    /** @param array<string, mixed> $document @return array<int, string> */
    private function assertDirectGroupRules(array $document): array
    {
        $groups = $document['groups'] ?? [];

        if (! is_array($groups) || ! array_is_list($groups)) {
            throw ValidationException::withMessages([
                'groups' => ['editor_document_groups_must_be_a_list'],
            ]);
        }

        Validator::validate(
            ['groups' => $groups],
            [
                'groups' => ['present', 'array'],
                'groups.*.id' => ['required', 'string', 'max:64'],
                'groups.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'groups.*.visible' => ['sometimes', 'boolean'],
                'groups.*.collapsed' => ['sometimes', 'boolean'],
                'groups.*.order' => ['sometimes', 'integer'],
            ],
        );

        return array_column($groups, 'id');
    }
}

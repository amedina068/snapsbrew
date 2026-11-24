<?php

namespace App\Services;

use App\Exceptions\IdempotencyConflictException;
use App\Jobs\ProcessSnap;
use App\Models\Snap;
use App\Models\SnapImport;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Support\InlineDesign;
use App\Support\Queues;
use Illuminate\Support\Facades\DB;

/**
 * Builds and dispatches a snap render. Shared by the REST SnapController and the
 * MCP create-snap tool so the render/dispatch path lives in exactly one place.
 *
 * Usage is intentionally NOT recorded here. Each transport records it at its
 * edge (REST via UsageRecorder::settle on the middleware ApiLog row, MCP via
 * UsageRecorder::recordMcpSnap), so the usage rules stay in one class while the
 * render mechanics are shared.
 */
class SnapService
{
    public function __construct(
        private IdempotencyClaimer $claimer,
        private SnapSourceVersioner $sourceVersioner,
    ) {}

    public function create(
        Template $template,
        ?array $variants,
        ?string $type,
        bool $async,
        ?string $webhookUrl,
        int $userId,
        ?int $companyId,
        ?string $format = null,
        bool $transparent = false,
        ?string $idempotencyKey = null,
    ): Snap {
        return $this->withIdempotency(
            $idempotencyKey,
            $companyId,
            fn () => $this->build($template, $variants, $type, $async, $webhookUrl, $userId, $companyId, $format, $transparent)
        );
    }

    /**
     * Render a design that lives on the request. The design becomes a
     * `template_versions` row with no parent template, which is the immutable
     * source the render reads, and the snap points at that row. Both rows are
     * written in one transaction, and the render starts only after the
     * transaction returns, because `ProcessSnap` reads the snap fresh and
     * returns in silence when the read finds nothing.
     *
     * Sync only. A design has no variants: the caller wrote every layer value.
     */
    public function createFromDesign(
        InlineDesign $design,
        ?string $type,
        int $userId,
        ?int $companyId,
        ?string $format = null,
        bool $transparent = false,
        ?string $idempotencyKey = null,
    ): Snap {
        return $this->withIdempotency($idempotencyKey, $companyId, function () use ($design, $type, $userId, $companyId, $format, $transparent) {
            $snap = DB::transaction(
                fn () => $this->createDesignSnapRow($design, $type, $userId, $companyId, $format, $transparent)
            );

            return $this->render($snap, false);
        });
    }

    /**
     * Write the source version and the snap row for a design. Call only inside
     * a transaction.
     */
    private function createDesignSnapRow(
        InlineDesign $design,
        ?string $type,
        int $userId,
        ?int $companyId,
        ?string $format,
        bool $transparent,
    ): Snap {
        $source = TemplateVersion::query()->create([
            'template_id' => null,
            'version_number' => 1,
            'layers' => $design->layers,
            'groups' => $design->groups,
            'width' => $design->width,
            'height' => $design->height,
            'published' => false,
            'created_by' => $userId,
        ]);

        return Snap::query()->create([
            'template_id' => null,
            'user_id' => $userId,
            'company_id' => $companyId,
            'source_template_version_id' => $source->id,
            'variants' => json_encode([]),
            'type' => $type ?? Snap::TYPE_IMAGE,
            'format' => $this->format($format),
            'transparent' => $transparent,
            'is_async' => false,
            'status' => Snap::STATUS_PROCESSING,
            'webhook_url' => null,
            'last_error' => null,
            'completed_at' => null,
        ]);
    }

    /**
     * Take the idempotency claim around a build, or answer a replay.
     *
     * @param  callable(): Snap  $build
     */
    private function withIdempotency(?string $idempotencyKey, ?int $companyId, callable $build): Snap
    {
        if ($idempotencyKey === null || $companyId === null) {
            return $build();
        }

        $claim = $this->claimer->claim($companyId, $idempotencyKey);

        // The claim resolved to a snap that an earlier request already made.
        // Return it untouched. The caller reads `wasRecentlyCreated` to decide
        // whether to charge.
        if ($claim instanceof Snap) {
            return $claim;
        }

        // A batch owns this key. Refuse before the build, or the request
        // renders a snap that no claim can record.
        if ($claim instanceof SnapImport) {
            throw IdempotencyConflictException::belongsToBatch();
        }

        try {
            $snap = $build();
        } catch (\Throwable $exception) {
            $this->claimer->release($claim);

            throw $exception;
        }

        $this->claimer->complete($claim, $snap);

        return $snap;
    }

    private function format(?string $format): string
    {
        return in_array($format, Snap::FORMATS, true) ? $format : 'png';
    }

    /**
     * Build a snap from an exact immutable source selected during package
     * preflight. This path deliberately does not inspect the template's
     * published version; ownership and source/template membership are checked
     * while the snap row is created.
     */
    public function createFromVersion(
        Template $template,
        TemplateVersion $source,
        ?array $variants,
        ?string $type,
        bool $async,
        ?string $webhookUrl,
        int $userId,
        int $companyId,
        ?string $format = null,
        bool $transparent = false,
        bool $keepErrorMessage = true,
    ): Snap {
        return $this->build(
            $template,
            $variants,
            $type,
            $async,
            $webhookUrl,
            $userId,
            $companyId,
            $format,
            $transparent,
            $source,
            $keepErrorMessage,
        );
    }

    /**
     * Create the snap row and run or queue the render.
     */
    protected function build(
        Template $template,
        ?array $variants,
        ?string $type,
        bool $async,
        ?string $webhookUrl,
        int $userId,
        ?int $companyId,
        ?string $format = null,
        bool $transparent = false,
        ?TemplateVersion $source = null,
        bool $keepErrorMessage = true,
    ): Snap {
        $type = $type ?? Snap::TYPE_IMAGE;
        $format = $this->format($format);

        $create = function (Template $lockedTemplate, TemplateVersion $selectedSource) use ($userId, $companyId, $variants, $type, $format, $transparent, $async, $webhookUrl) {
            return $lockedTemplate->snaps()->create([
                'user_id' => $userId,
                'company_id' => $companyId,
                'source_template_version_id' => $selectedSource->id,
                'variants' => json_encode($variants),
                'type' => $type,
                'format' => $format,
                'transparent' => $transparent,
                'is_async' => $async,
                'status' => $async ? Snap::STATUS_PENDING : Snap::STATUS_PROCESSING,
                'webhook_url' => $async ? $webhookUrl : null,
                'last_error' => null,
                'completed_at' => null,
            ]);
        };

        $snap = $source
            ? $this->sourceVersioner->createSnapFromVersion($template, $source, (int) $companyId, $create)
            : $this->sourceVersioner->createSnap($template, $create);

        return $this->render($snap, $async, $keepErrorMessage);
    }

    /**
     * Queue or run the render for a committed snap row. A failed sync render
     * stores Snap::ERROR_RENDER_FAILED and logs the message. A caller that keeps
     * its own diagnostic passes `$keepErrorMessage` false, and a failed sync
     * render then leaves `last_error` null and logs nothing.
     */
    private function render(Snap $snap, bool $async, bool $keepErrorMessage = true): Snap
    {
        if ($async) {
            ProcessSnap::dispatch($snap)->onQueue(Queues::RENDERS);
        } else {
            try {
                ProcessSnap::dispatchSync($snap);
            } catch (\Throwable $e) {
                // ProcessSnap::failed() wrote the code on a fresh row. A keyed
                // update overwrites it. A model save would skip a null that is
                // not dirty on this instance. A caller that keeps its own
                // diagnostic gets no code and no log line of the message.
                $snap->newQuery()->whereKey($snap->getKey())->update([
                    'status' => Snap::STATUS_FAILED,
                    'last_error' => $keepErrorMessage ? Snap::ERROR_RENDER_FAILED : null,
                ]);

                if ($keepErrorMessage) {
                    ProcessSnap::logRenderFailure($snap, $e);
                }

                throw $e;
            }
        }

        // fresh() reads a queried model, so preserve the creation flag that
        // transport callers use to decide whether a newly-created snap bills.
        $created = $snap->wasRecentlyCreated;
        $snap = $snap->fresh();
        $snap->wasRecentlyCreated = $created;

        return $snap;
    }
}

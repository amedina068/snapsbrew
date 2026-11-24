<?php

namespace App\Services;

use App\Models\ApiLog;
use App\Models\CreativePackageItem;
use App\Models\Snap;
use Illuminate\Support\Facades\DB;

/**
 * The one place that writes the usage rows of the api_logs table.
 *
 * Every surface that renders (sync API, embedded forms, signed URLs, async
 * API, imports, MCP and creative packages) records its renders here, so the
 * rules for what counts as a render live in one file. The usage log is a
 * history. It holds no balance and it never blocks a render.
 *
 * Rules:
 *  - One ApiLog row per unit of work.
 *  - A row counts as a render when `renders` is above zero, `method` is POST
 *    and `status_code` is in ApiLog::SUCCESSFUL_STATUSES.
 *  - Async work is recorded when it completes, never when it is accepted.
 *    A 202 response records zero renders.
 */
class UsageRecorder
{
    public const FEATURE_API = 'api';

    public const FEATURE_FORM = 'form';

    public const FEATURE_SIGNED_URL = 'signed_url';

    public const FEATURE_SNAP_IMPORT = 'snap_import';

    public const FEATURE_MCP = 'mcp';

    public const TYPE_CREATIVE_PACKAGE = 'creative_package';

    /**
     * Complete the row that a request-logging middleware (LogApiRequest,
     * SignedUrl, EmbeddedForm) created for this request.
     *
     * Pass `$renders = 0` and a non-2xx `$statusCode` for a failure, so the
     * row is kept but not counted.
     */
    public static function settle(
        ApiLog $apiLog,
        int $renders,
        string $feature,
        string $type,
        int $statusCode = 201,
        $response = null,
    ): ApiLog {
        $payload = [
            'renders' => $renders,
            'feature' => $feature,
            'type' => $type,
            'status_code' => $statusCode,
        ];

        if ($response !== null) {
            $payload['response'] = is_string($response) ? $response : json_encode($response);
        }

        $apiLog->update($payload);

        return $apiLog;
    }

    /**
     * Record one render for an async snap that just reached COMPLETED.
     *
     * Covers import snaps (feature snap_import) and direct async API snaps
     * (feature api). A snap of a creative package item is recorded by
     * recordPackageItem(), so this method skips it.
     */
    public static function recordCompletedAsyncSnap(Snap $snap): ?ApiLog
    {
        if (! $snap->is_async || CreativePackageItem::query()->where('snap_id', $snap->id)->exists()) {
            return null;
        }

        $isImport = (bool) $snap->snap_import_id;

        FirstSnapMilestone::recordIfFirst($snap);

        return self::write(
            userId: $snap->user_id,
            url: $isImport
                ? 'snap-import:'.optional($snap->import)->uuid
                : 'snap:'.$snap->uuid,
            request: [
                'snap_id' => $snap->id,
                'snap_uuid' => $snap->uuid,
                'template_uuid' => optional($snap->template)->uuid,
                'snap_import_id' => $snap->snap_import_id,
            ],
            feature: $isImport ? self::FEATURE_SNAP_IMPORT : self::FEATURE_API,
            type: $isImport ? self::FEATURE_SNAP_IMPORT : ($snap->type ?? 'image'),
        );
    }

    /**
     * Record one render for a sync snap that the MCP server created. MCP
     * requests do not pass through the request-logging middleware, so this
     * writes a new row.
     */
    public static function recordMcpSnap(Snap $snap): ApiLog
    {
        FirstSnapMilestone::recordIfFirst($snap);

        return self::write(
            userId: $snap->user_id,
            url: 'mcp:snap:'.$snap->uuid,
            request: [
                'snap_id' => $snap->id,
                'snap_uuid' => $snap->uuid,
                'template_uuid' => optional($snap->template)->uuid,
            ],
            feature: self::FEATURE_MCP,
            type: $snap->type ?? 'image',
        );
    }

    /**
     * Record the render of one finished creative package item exactly one
     * time. The item row is locked, and an item that already points at its
     * usage row returns at once, so a replay writes nothing.
     */
    public static function recordPackageItem(CreativePackageItem $item): CreativePackageItem
    {
        return DB::transaction(function () use ($item) {
            $locked = CreativePackageItem::query()
                ->whereKey($item->id)
                ->where('company_id', $item->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->api_log_id !== null) {
                return $locked;
            }

            $apiLog = self::write(
                userId: $locked->package->created_by,
                url: 'mcp:creative-package-item:'.$locked->uuid,
                request: [
                    'creative_package_item_id' => $locked->id,
                    'creative_package_item_uuid' => $locked->uuid,
                    'creative_package_uuid' => $locked->package->uuid,
                    'snap_id' => $locked->snap_id,
                    'template_uuid' => $locked->source_template_uuid,
                ],
                feature: self::FEATURE_MCP,
                type: self::TYPE_CREATIVE_PACKAGE,
            );

            $locked->update(['api_log_id' => $apiLog->id]);

            return $locked->fresh();
        });
    }

    private static function write(
        int $userId,
        string $url,
        array $request,
        string $feature,
        string $type,
    ): ApiLog {
        return ApiLog::create([
            'user_id' => $userId,
            'url' => $url,
            'method' => ApiLog::POST_METHOD,
            'ip_address' => '0.0.0.0',
            'request' => json_encode($request),
            'status_code' => 201,
            'renders' => 1,
            'feature' => $feature,
            'type' => $type,
        ]);
    }
}

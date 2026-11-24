<?php

namespace App\Services;

use App\Models\SnapImport;
use Illuminate\Support\Facades\Log;

class SnapImportWebhookDispatcher
{
    public const MAX_ATTEMPTS = WebhookSender::MAX_ATTEMPTS;

    public static function dispatch(SnapImport $import, string $event): void
    {
        if (! $import->webhook_url) {
            return;
        }

        $import->loadMissing('template', 'company');

        $payload = [
            'event' => $event,
            'import' => $import->progressSnapshot(),
            'template' => [
                'uuid' => optional($import->template)->uuid,
                'title' => optional($import->template)->title,
            ],
        ];

        $error = WebhookSender::deliver($import->webhook_url, $payload, $import->company->webhook_secret);

        if ($error === null) {
            return;
        }

        Log::warning('Failed to deliver snap import webhook.', [
            'import_id' => $import->id,
            'import_uuid' => $import->uuid,
            'webhook_url' => $import->webhook_url,
            'event' => $event,
            'attempts' => self::MAX_ATTEMPTS,
            'error' => $error,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ApiLog;
use App\Models\Form;
use App\Models\Snap;
use App\Models\Template;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LogController extends Controller
{
    public function index()
    {
        $apiLogs = ApiLog::query()
            ->where('user_id', Auth::id())
            ->latest('id')
            ->paginate();

        $breadcrumbs = [
            ['label' => 'Usage Logs', 'current' => true],
        ];

        return view('logs.index', compact('apiLogs', 'breadcrumbs'));
    }

    public function show($logUuid)
    {
        $apiLog = ApiLog::query()
            ->with('token')
            ->where('user_id', Auth::id())
            ->uuid($logUuid)
            ->firstOrFail();

        $requestPayload = $this->decodePayload($apiLog->request);
        $responsePayload = $this->decodePayload($apiLog->response);

        $template = $this->resolveTemplate($requestPayload, $responsePayload, $apiLog->url);
        $snap = $this->resolveSnap($requestPayload, $responsePayload, $apiLog->url);

        if ($snap && ! $template) {
            $template = $snap->template;
        }

        $form = $this->resolveForm($requestPayload, $responsePayload, $apiLog->url);

        if ($snap && ! $form) {
            $form = $snap->form;

            if ($form && ! $form->relationLoaded('formable')) {
                $form->load('formable');
            }
        }

        $templateLink = $template ? url("/templates/{$template->uuid}") : null;
        $snapLink = $snap ? url("/snapshots/{$snap->uuid}") : null;
        $formLink = $this->resolveFormLink($form);
        $apiKey = $apiLog->token;

        $breadcrumbs = [
            ['label' => 'Usage Logs', 'href' => url('/logs')],
            ['label' => 'Log '.$apiLog->uuid, 'current' => true],
        ];

        return view('logs.show', compact(
            'apiLog',
            'template',
            'snap',
            'form',
            'templateLink',
            'snapLink',
            'formLink',
            'apiKey',
            'breadcrumbs'
        ));
    }

    private function decodePayload(?string $payload): array
    {
        if (! $payload) {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function resolveTemplate(array $requestPayload, array $responsePayload, ?string $url): ?Template
    {
        $uuid = $this->firstUuidFromPayload($requestPayload, ['template_id', 'template_uuid'])
            ?? $this->firstUuidFromPayload($responsePayload, ['template_id', 'template_uuid'])
            ?? $this->findUuidInUrl($url, 'templates');

        if (! $uuid) {
            return null;
        }

        return Template::query()
            ->where('company_id', Auth::user()->company_id)
            ->uuid($uuid)
            ->first();
    }

    private function resolveSnap(array $requestPayload, array $responsePayload, ?string $url): ?Snap
    {
        $uuid = $this->firstUuidFromPayload($responsePayload, ['id', 'uuid', 'snap.id', 'snap.uuid', 'snap_uuid'])
            ?? $this->firstUuidFromPayload($requestPayload, ['snap_uuid'])
            ?? $this->findUuidInUrl($url, 'snaps');

        if (! $uuid) {
            return null;
        }

        return Snap::query()
            ->where('user_id', Auth::id())
            ->with(['template', 'form.formable'])
            ->uuid($uuid)
            ->first();
    }

    private function resolveForm(array $requestPayload, array $responsePayload, ?string $url): ?Form
    {
        $uuid = $this->firstUuidFromPayload($requestPayload, ['form_id', 'form_uuid'])
            ?? $this->firstUuidFromPayload($responsePayload, ['form_id', 'form_uuid'])
            ?? $this->findUuidInUrl($url, 'forms');

        if (! $uuid) {
            return null;
        }

        return Form::query()
            ->where('company_id', Auth::user()->company_id)
            ->with('formable')
            ->uuid($uuid)
            ->first();
    }

    private function resolveFormLink(?Form $form): ?string
    {
        if (! $form || ! $form->formable) {
            return null;
        }

        if ($form->isForTemplate()) {
            return url("/templates/{$form->formable->uuid}/forms/{$form->uuid}");
        }

        if ($form->isForCollection()) {
            return url("/collections/{$form->formable->uuid}/forms/{$form->uuid}");
        }

        return null;
    }

    private function firstUuidFromPayload(array $payload, array $keys): ?string
    {
        if (empty($payload)) {
            return null;
        }

        $flattened = Arr::dot($payload);

        foreach ($keys as $key) {
            if (! array_key_exists($key, $flattened)) {
                continue;
            }

            $value = $flattened[$key];

            if (is_string($value) && Str::isUuid($value)) {
                return $value;
            }
        }

        return null;
    }

    private function findUuidInUrl(?string $url, string $segment): ?string
    {
        if (! $url) {
            return null;
        }

        $pattern = sprintf('/%s\/([0-9a-fA-F-]{36})/', preg_quote($segment, '/'));

        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }

        return null;
    }
}

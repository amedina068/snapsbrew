<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\IdempotencyConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SnapResource;
use App\Http\Responses\InternalError;
use App\Models\ApiLog;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateCollection;
use App\Rules\SafeUrl;
use App\Services\SnapService;
use App\Services\UsageRecorder;
use App\Support\IdempotencyRules;
use App\Support\VariantRules;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SnapController extends Controller
{
    public function store(Request $request)
    {
        try {
            $request->validate([
                'collection_id' => [
                    'string',
                    'required_without:template_id',
                    'prohibits:template_id',
                    Rule::exists('template_collections', 'uuid')->where('company_id', Auth::user()->company_id),
                ],
                'template_id' => [
                    'string',
                    'required_without:collection_id',
                    'prohibits:collection_id',
                    Rule::exists('templates', 'uuid')->where('company_id', Auth::user()->company_id),
                ],
                'type' => [
                    'string',
                    Rule::in(Snap::TYPES),
                    'nullable',
                ],
                'format' => [
                    'sometimes',
                    'string',
                    Rule::in(Snap::FORMATS),
                ],
                'transparent' => [
                    'sometimes',
                    'boolean',
                ],
                'async' => [
                    'sometimes',
                    'boolean',
                ],
                'webhook_url' => [
                    'required_if:async,true',
                    'url',
                    // SafeUrl, not just `url`. ProcessSnap POSTs to this address
                    // from the render host. See the comment in
                    // SnapImportController::store().
                    new SafeUrl,
                ],
                // The collection path turns one request into one snap per
                // template, which a single key row cannot describe, so refuse
                // the pair instead of accepting the key and ignoring it. Every
                // other rule is shared with the MCP tool.
                'idempotency_key' => array_merge(
                    IdempotencyRules::rules()['idempotency_key'],
                    ['prohibits:collection_id'],
                ),
            ] + VariantRules::rules(), IdempotencyRules::messages() + [
                'idempotency_key.prohibits' => 'idempotency_key is not supported with collection_id. A collection request creates one snap per template, and one key cannot describe them all.',
            ]);

            // Transparency needs an alpha channel; JPEG has none. Reject rather
            // than silently flatten to a white background.
            if ($request->input('format') === 'jpeg' && $request->boolean('transparent')) {
                throw ValidationException::withMessages([
                    'transparent' => 'Transparency is not supported for the JPEG format.',
                ]);
            }

            $async = $request->boolean('async');
            $webhookUrl = $request->input('webhook_url');

            if ($request->collection_id) {
                return $this->handleCollectionRequest($request, $async, $webhookUrl);
            }

            if ($request->template_id) {
                return $this->handleTemplateRequest($request, $async, $webhookUrl);
            }
        } catch (IdempotencyConflictException $e) {
            // Must sit before the generic Exception branch below, which would
            // turn this into a 500. Nothing was created and nothing was recorded.
            return $this->response($request, [
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], 409, 0);
        } catch (ValidationException $e) {
            return $this->response($request, [
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422, 0);
        } catch (AuthorizationException $e) {
            return $this->response($request, [
                'message' => $e->getMessage(),
            ], 403, 0);
        } catch (Exception $e) {
            return $this->response($request, InternalError::report($e, $request, 'snap', [
                'template_id' => $request->input('template_id'),
                'collection_id' => $request->input('collection_id'),
            ]), 500, 0);
        }
    }

    private function handleCollectionRequest(Request $request, bool $async, ?string $webhookUrl)
    {
        $templateCollection = TemplateCollection::query()
            ->uuid($request->collection_id)
            ->where('company_id', Auth::user()->company_id)
            ->first();

        if (! $templateCollection) {
            return $this->response($request, [
                'message' => 'The collection you are trying to use doesn\'t exist.',
            ], 404, 0);
        }

        $templates = $templateCollection->templates()
            ->where('templates.company_id', Auth::user()->company_id)
            ->get();

        if (! $templates->count()) {
            return $this->response($request, [
                'message' => 'The collection doesn\'t have any templates.',
            ], 404, 0);
        }

        $snaps = $templates->map(function ($template) use ($request, $async, $webhookUrl) {
            return $this->createSnap($template, $request->variants, $request->type, $async, $webhookUrl, $request)->append('imageUrl');
        });

        // SnapResource reads the source version of every row.
        $snaps->load('sourceTemplateVersion');

        $statusCode = $async ? 202 : 201;

        $renders = $async ? 0 : $snaps->count();

        return $this->response(
            $request,
            SnapResource::collection($snaps),
            $statusCode,
            $renders
        );
    }

    private function handleTemplateRequest(Request $request, bool $async, ?string $webhookUrl)
    {
        $template = Template::query()
            ->uuid($request->template_id)
            ->where('company_id', Auth::user()->company_id)
            ->first();

        if (! $template) {
            return $this->response($request, [
                'message' => 'The template you are trying to use doesn\'t exist.',
            ], 404, 0);
        }

        $snap = $this->createSnap($template, $request->variants, $request->type, $async, $webhookUrl, $request);

        // A replay returns the snap an earlier request already made and
        // recorded. Record no render, whether the original call was sync or async.
        $replayed = ! $snap->wasRecentlyCreated;

        $snap->append('imageUrl');

        $statusCode = $async ? 202 : 201;
        $renders = ($async || $replayed) ? 0 : 1;

        return $this->response($request, new SnapResource($snap), $statusCode, $renders, $replayed);
    }

    private function createSnap(Template $template, $variants, $type, bool $async, ?string $webhookUrl, Request $request)
    {
        return app(SnapService::class)->create(
            $template,
            $variants,
            $type,
            $async,
            $webhookUrl,
            Auth::id(),
            Auth::user()->company_id,
            $request->input('format'),
            $request->boolean('transparent'),
            $request->input('idempotency_key'),
        );
    }

    private function response($request, $response, $statusCode, $renders, bool $replayed = false)
    {
        $apiLog = ApiLog::uuid($request->api_log_uuid)->first();

        UsageRecorder::settle(
            $apiLog,
            renders: $renders,
            feature: UsageRecorder::FEATURE_API,
            type: $request->type ?? 'image',
            statusCode: $statusCode,
            response: $response,
        );

        $headers = ['X-Request-Uuid' => $apiLog->uuid];

        // Tells the client this response repeats an earlier one, so it can see
        // that no second render ran.
        if ($replayed) {
            $headers['X-Idempotent-Replay'] = 'true';
        }

        return response()
            ->json($response, $statusCode)
            ->withHeaders($headers);
    }
}

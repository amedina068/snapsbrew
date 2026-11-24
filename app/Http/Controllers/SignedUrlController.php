<?php

namespace App\Http\Controllers;

use App\Builders\SnapBuilder;
use App\Models\ApiLog;
use App\Models\Snap;
use App\Services\UsageRecorder;
use App\Support\VariantRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

class SignedUrlController extends Controller
{
    public function store(Request $request)
    {
        $template = $request->template;

        $variants = json_decode(base64_decode($request->variants), true);

        // S5: the HMAC proves the URL was issued by us, not that the variant
        // payload is safe. A legitimately-signed URL can still carry an SSRF
        // image_url or CSS-injecting color, so run the same variant rules the
        // REST/MCP surfaces use before anything reaches the renderer.
        $validator = Validator::make(
            ['variants' => $variants],
            VariantRules::rules()
        );

        if ($validator->fails()) {
            abort(400, 'Invalid variants payload.');
        }

        // Optional output format (HMAC-covered like every other query param).
        $format = in_array($request->query('format'), Snap::FORMATS, true) ? $request->query('format') : 'png';
        $transparent = filter_var($request->query('transparent'), FILTER_VALIDATE_BOOLEAN);

        if ($format === 'jpeg' && $transparent) {
            abort(400, 'Transparency is not supported for the JPEG format.');
        }

        $apiLog = ApiLog::uuid($request->api_log_uuid)->first();

        try {
            $snap = $this->generateSnap($template, $apiLog->user_id, $variants, $request->identifier, $format, $transparent);
        } catch (Throwable $exception) {
            // Record the failure with no render. The `renders` column
            // defaults to 1, so a row that is never settled reads as a render.
            UsageRecorder::settle(
                $apiLog,
                renders: 0,
                feature: UsageRecorder::FEATURE_SIGNED_URL,
                type: 'image',
                statusCode: 500,
            );

            throw $exception;
        }

        UsageRecorder::settle(
            $apiLog,
            renders: 1,
            feature: UsageRecorder::FEATURE_SIGNED_URL,
            type: 'image',
            statusCode: 201,
            response: $snap->imagePath,
        );

        return redirect($snap->imageUrl);
    }

    private function generateSnap($template, int $userId, $variants, $identifier, string $format = 'png', bool $transparent = false)
    {
        $snapBuilder = (new SnapBuilder)
            ->setLayers($template->renderableLayers())
            ->setGroups($template->renderableGroups())
            ->forCompany($template->company_id)
            ->setWidth($template->width)
            ->setHeight($template->height)
            ->withVariables($variants);

        $snap = $template->snaps()->create([
            'user_id' => $userId,
            'company_id' => $template->company_id,
            'identifier' => $identifier,
            'type' => 'image',
            'format' => $format,
            'transparent' => $transparent,
            'variants' => json_encode($variants),
        ]);

        // The identifier makes this row a cache entry for the URL. If the render
        // fails, delete the row, or every later request finds it and redirects
        // to an empty image URL. The exception still reaches the handler.
        try {
            $snap->addContent($snapBuilder->buildContent());
            $snap->addHead($snapBuilder->buildHead());

            if ($snap->getImage() === '') {
                throw new RuntimeException("Signed-url snap {$snap->uuid} rendered but stored no image.");
            }
        } catch (Throwable $exception) {
            $snap->delete();

            throw $exception;
        }

        activity()
            ->performedOn($snap)
            ->withProperties([
                'layers' => json_decode($template->layers, true),
                'width' => $template->width,
                'height' => $template->height,
                'variants' => $variants,
            ])
            ->causedBy(Auth::user())
            ->log('created');

        return $snap->append('imageUrl');
    }
}

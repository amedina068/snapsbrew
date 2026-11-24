<?php

use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TemplateTagController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\GuideController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\PreviewController;
use App\Http\Controllers\Api\V1\ScreenshotController;
use App\Http\Controllers\Api\V1\SnapBatchController;
use App\Http\Controllers\Api\V1\SnapController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\Api\V1\TemplateDocumentController;
use App\Http\Controllers\Api\V1\Tools;
use App\Http\Middleware\EnsureFreeToolsAreEnabled;
use Illuminate\Support\Facades\Route;

// Free public tools. They take no token and render one fixed template each.
// The app has no tools web pages. The forms of the marketing site call these
// routes from the browser. The routes return 404 until
// SNAPSBREW_FREE_TOOLS_ENABLED is true. The `free-tools` limiter allows a few
// requests for each IP.
Route::middleware([EnsureFreeToolsAreEnabled::class, 'throttle:free-tools'])->group(function () {
    Route::post('v1/tools/certificate-creator', [Tools\CertificateCreatorController::class, 'store']);
    Route::post('v1/tools/twitter-graph-image-creator', [Tools\TwitterGraphImageCreatorController::class, 'store']);
    Route::post('v1/tools/testimonial-image-creator', [Tools\TestimonialImageCreatorController::class, 'store']);
    Route::post('v1/tools/screenshot-creator', [Tools\ScreenshotCreatorController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'abilities:api_requests', 'throttle:preview'])->group(function () {
    Route::get('v1/templates/{templateUuid}/preview', [PreviewController::class, 'template']);
    Route::get('v1/snaps/preview', [PreviewController::class, 'snap']);
});

// The guidance catalogs are static vocabulary. They read no table and no
// company. They carry no `logApiRequest` on purpose: a call that never renders
// does not belong in the usage log the customer reads. `GET /v1/templates/sizes`
// is the known outlier, because it serves the same kind of static catalog and
// it still logs.
Route::middleware(['auth:sanctum', 'abilities:api_requests'])->group(function () {
    Route::get('v1/guides/design', [GuideController::class, 'design']);
    Route::get('v1/guides/layers', [GuideController::class, 'layers']);
    Route::get('v1/guides/variant-properties', [GuideController::class, 'variantProperties']);
    Route::get('v1/guides/editor-document', [GuideController::class, 'editorDocument']);
    // The index route is declared above its detail route.
    // These two routes differ by a path segment, so here the order is a reading
    // convention. The order is a real constraint at `v1/templates/sizes` below,
    // because that literal segment and `{templateUuid}` match the same path.
    Route::get('v1/guides/platforms', [GuideController::class, 'platforms']);
    Route::get('v1/guides/platforms/{platform}', [GuideController::class, 'platform']);
    Route::get('v1/guides/purposes', [GuideController::class, 'purposes']);
    Route::get('v1/guides/purposes/{purpose}', [GuideController::class, 'purpose']);
});

Route::middleware(['auth:sanctum', 'abilities:api_requests'])->group(function () {
    Route::delete('v1/templates/{templateIdentifier}', [TemplateController::class, 'destroy']);
    Route::get('v1/media', [MediaController::class, 'index']);
    Route::post('v1/media', [MediaController::class, 'store']);
    Route::post('v1/templates', [TemplateController::class, 'store']);
    Route::patch('v1/templates/{templateIdentifier}', [TemplateController::class, 'update']);
    Route::post('v1/templates/{templateUuid}/publish', [TemplateController::class, 'publish']);
    Route::middleware('throttle:editor-document')->group(function () {
        Route::get('v1/templates/{templateUuid}/document/preflight', [TemplateDocumentController::class, 'preflight']);
        Route::get('v1/templates/{templateUuid}/document', [TemplateDocumentController::class, 'show']);
        Route::put('v1/templates/{templateUuid}/document', [TemplateDocumentController::class, 'update']);
        Route::patch('v1/templates/{templateUuid}/document', [TemplateDocumentController::class, 'apply']);
    });
});

Route::middleware(['auth:sanctum', 'logApiRequest', 'abilities:api_requests'])->group(function () {
    Route::get('v1/auth', [AuthController::class, 'index']);
    Route::get('v1/templates', [TemplateController::class, 'index']);
    // Must stay above the {templateUuid} route — `sizes` is a literal segment.
    Route::get('v1/templates/sizes', [TemplateController::class, 'sizes']);
    Route::get('v1/templates/{templateUuid}', [TemplateController::class, 'show']);
    Route::get('v1/screenshots', [ScreenshotController::class, 'index']);
    Route::get('v1/screenshots/{screenshotUuid}', [ScreenshotController::class, 'show']);
    Route::get('v1/tags', [TagController::class, 'index']);
    Route::post('v1/tags', [TagController::class, 'store']);
    Route::post('v1/templates/{templateUuid}/tags', [TemplateTagController::class, 'store']);

    Route::get('v1/snaps/batches/{batchUuid}', [SnapBatchController::class, 'show']);
    Route::post('v1/snaps/batches/{batchUuid}/cancel', [SnapBatchController::class, 'cancel']);
    Route::post('v1/screenshots', [ScreenshotController::class, 'store']);
    Route::post('v1/snaps', [SnapController::class, 'store']);
    Route::post('v1/snaps/batches', [SnapBatchController::class, 'store']);
});

<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\CompanyInvitationController;
use App\Http\Controllers\CompanyUserRoleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\EmbeddedFormController;
use App\Http\Controllers\FontController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\OAuthConnectionController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ScreenshotController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SignedUrlController;
use App\Http\Controllers\SignedUrlKeyController;
use App\Http\Controllers\SnapCreateController;
use App\Http\Controllers\SnapImportCancelController;
use App\Http\Controllers\SnapImportController;
use App\Http\Controllers\SnapImportStatusController;
use App\Http\Controllers\SnapshotController;
use App\Http\Controllers\TemplateCollectionController;
use App\Http\Controllers\TemplateCollectionFormController;
use App\Http\Controllers\TemplateCollectionTemplateController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TemplateEditorController;
use App\Http\Controllers\TemplateNameController;
use App\Http\Controllers\TemplateVersionController;
use App\Http\Controllers\WebhookSecretController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(Auth::check() ? 'dashboard' : 'login'));

// The first-run page. It creates the owner account and closes when a user exists.
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('setup', [SetupController::class, 'show']);
    Route::post('setup', [SetupController::class, 'store']);
});

Route::middleware(['throttle:signed-url', 'signedUrlMiddleware'])->get('signed-url/image.jpg', [SignedUrlController::class, 'store']);

// Onboarding wizard — auth only, NOT gated by `onboarded` so new users can reach it.
Route::middleware('auth')->group(function () {
    Route::get('onboarding', [OnboardingController::class, 'show']);

    Route::middleware('throttle:6,1')->group(function () {
        // These steps change the company and send mail. They close when
        // onboarding is complete. `finish` stays open because it changes
        // nothing a second time.
        Route::middleware('onboarding.open')->group(function () {
            Route::post('onboarding/profile', [OnboardingController::class, 'storeProfile']);
            Route::post('onboarding/categories', [OnboardingController::class, 'storeCategories']);
            Route::post('onboarding/invites', [OnboardingController::class, 'storeInvites']);
        });
        Route::post('onboarding/finish', [OnboardingController::class, 'finish']);
    });
});

Route::middleware(['auth', 'onboarded'])->group(function () {
    Route::post('tokens', [ApiTokenController::class, 'store']);
    Route::post('signed-url-keys', [SignedUrlKeyController::class, 'store']);
    Route::post('screenshots', [ScreenshotController::class, 'store']);

    Route::get('account', [AccountController::class, 'index']);
    Route::post('account/invitations', [CompanyInvitationController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('account/invitations/{invitation}', [CompanyInvitationController::class, 'destroy']);
    Route::patch('account/users/{user}/role', [CompanyUserRoleController::class, 'update'])->whereNumber('user')->middleware('throttle:10,1');
    Route::post('account/webhook-secret', [WebhookSecretController::class, 'store'])->middleware('throttle:6,1');
    Route::get('logs', [LogController::class, 'index']);
    Route::get('logs/{logUuid}', [LogController::class, 'show']);

    Route::get('collections', [TemplateCollectionController::class, 'index']);
    Route::post('collections', [TemplateCollectionController::class, 'store']);
    Route::get('collections/new', [TemplateCollectionController::class, 'create']);
    Route::get('collections/{templateCollectionUuid}', [TemplateCollectionController::class, 'show']);
    Route::delete('collections/{templateCollectionUuid}', [TemplateCollectionController::class, 'destroy']);
    Route::post('collections/{templateCollectionUuid}/templates', [TemplateCollectionTemplateController::class, 'store']);
    Route::delete('collections/{templateCollectionUuid}/templates/{templateUuid}', [TemplateCollectionTemplateController::class, 'destroy']);
    Route::get('collections/{templateCollectionUuid}/templates/new', [TemplateCollectionTemplateController::class, 'create']);
    Route::get('collections/{templateCollectionUuid}/forms', [TemplateCollectionFormController::class, 'index']);
    Route::post('collections/{templateCollectionUuid}/forms', [TemplateCollectionFormController::class, 'store']);
    Route::get('collections/{templateCollectionUuid}/forms/new', [TemplateCollectionFormController::class, 'create']);
    Route::get('collections/{templateCollectionUuid}/forms/{formUuid}', [TemplateCollectionFormController::class, 'show']);
    Route::patch('collections/{templateCollectionUuid}/forms/{formUuid}', [TemplateCollectionFormController::class, 'update']);
    Route::get('collections/{templateCollectionUuid}/forms/{formUuid}/edit', [TemplateCollectionFormController::class, 'edit']);

    Route::get('dashboard', [DashboardController::class, 'index']);

    // Custom font management (web-only v1).
    Route::get('fonts', [FontController::class, 'index']);
    Route::get('fonts/list', [FontController::class, 'list']);
    Route::post('fonts', [FontController::class, 'store']);
    Route::delete('fonts/{uuid}', [FontController::class, 'destroy']);

    // Template versioning (draft / publish / revert).
    Route::get('templates/{templateId}/versions', [TemplateVersionController::class, 'index']);
    Route::get('templates/{templateId}/versions/{versionId}/preview', [TemplateVersionController::class, 'preview']);
    Route::post('templates/{templateId}/publish', [TemplateVersionController::class, 'publish']);
    Route::post('templates/{templateId}/versions/{versionId}/revert', [TemplateVersionController::class, 'revert']);

    // Generate a snap from a template — reached from the template's Snaps section.
    Route::get('snaps/{snapUuid}/status', [SnapCreateController::class, 'status']);
    Route::get('templates/{templateId}/generate', [SnapCreateController::class, 'create']);
    Route::post('templates/{templateId}/generate', [SnapCreateController::class, 'store']);

    Route::delete('tokens/{tokenId}', [ApiTokenController::class, 'destroy']);
    Route::delete('signed-url-keys/{apiKey}', [SignedUrlKeyController::class, 'destroy']);
    Route::delete('account/oauth-connections/{clientId}', [OAuthConnectionController::class, 'destroy']);

    Route::get('snapshots', [SnapshotController::class, 'index']);
    Route::delete('snapshots', [SnapshotController::class, 'bulkDestroy']);
    Route::get('snapshots/{snapshotUuid}', [SnapshotController::class, 'show']);
    Route::delete('snapshots/{snapshotUuid}', [SnapshotController::class, 'destroy']);

    Route::get('screenshots', [ScreenshotController::class, 'index']);
    Route::get('screenshots/new', [ScreenshotController::class, 'create']);
    Route::get('screenshots/{screenshotId}', [ScreenshotController::class, 'show']);

    Route::get('templates', [TemplateController::class, 'index']);
    Route::post('templates', [TemplateController::class, 'store']);
    Route::get('templates/new', [TemplateController::class, 'create']);
    Route::get('templates/{templateId}', [TemplateController::class, 'show']);
    Route::patch('templates/{templateId}', [TemplateController::class, 'update']);
    Route::delete('templates/{templateId}', [TemplateController::class, 'destroy']);
    Route::get('templates/{templateId}/edit', [TemplateController::class, 'edit']);
    Route::get('templates/{templateId}/editor', [TemplateEditorController::class, 'edit']);
    Route::patch('templates/{templateId}/rename', [TemplateNameController::class, 'update']);

    Route::get('templates/{templateId}/forms', [FormController::class, 'index']);
    Route::post('templates/{templateId}/forms', [FormController::class, 'store']);
    Route::get('templates/{templateId}/forms/new', [FormController::class, 'create']);
    Route::get('templates/{templateId}/forms/{formUuid}', [FormController::class, 'show']);
    Route::patch('templates/{templateId}/forms/{formUuid}', [FormController::class, 'update']);
    Route::get('templates/{templateId}/forms/{formUuid}/edit', [FormController::class, 'edit']);

    Route::get('templates/{templateId}/imports', [SnapImportController::class, 'index']);
    Route::get('templates/{templateId}/imports/create', [SnapImportController::class, 'create']);
    Route::post('templates/{templateId}/imports', [SnapImportController::class, 'store']);
    Route::get('templates/{templateId}/imports/export', [SnapImportController::class, 'downloadTemplate']);
    Route::get('templates/{templateId}/imports/{importUuid}', [SnapImportController::class, 'show']);

    Route::get('imports/{importUuid}/status', SnapImportStatusController::class);
    Route::post('imports/{importUuid}/cancel', SnapImportCancelController::class);
});

// The admin area of the instance. Only the owner gets in. Everyone else,
// guests included, gets a 404 from the `owner` middleware.
Route::middleware('owner')->prefix('admin')->name('admin.')->group(function () {
    Route::get('users', [Admin\UserController::class, 'index'])->name('users.index');
    Route::post('users/{user}/deactivation', [Admin\UserDeactivationController::class, 'store'])->name('users.deactivate');
    Route::delete('users/{user}/deactivation', [Admin\UserDeactivationController::class, 'destroy'])->name('users.reactivate');
    Route::delete('users/{user}', [Admin\UserController::class, 'destroy'])->name('users.destroy');

    Route::get('settings', [Admin\SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [Admin\SettingsController::class, 'update'])->name('settings.update');
});

Route::prefix('documentation')->name('documentation.')->group(function () {
    Route::get('/', [DocumentationController::class, 'index'])->name('index');
    Route::get('api-reference', [DocumentationController::class, 'reference'])->name('reference');

    // Static info pages rendered from dedicated Blade views.
    Route::get('rate-limit', [DocumentationController::class, 'page'])->defaults('view', 'rate-limit')->name('rate-limit');
    Route::get('troubleshooting', [DocumentationController::class, 'page'])->defaults('view', 'troubleshooting')->name('troubleshooting');
    Route::get('guides/mcp', [DocumentationController::class, 'page'])->defaults('view', 'guides.mcp')->name('guides.mcp');
    Route::get('guides/embedded-forms', [DocumentationController::class, 'page'])->defaults('view', 'guides.embedded-forms')->name('guides.embedded-forms');
    Route::get('guides/signed-urls', [DocumentationController::class, 'page'])->defaults('view', 'guides.signed-urls')->name('guides.signed-urls');

    // Machine-readable exports — all built on the fly from ApiSpec.
    Route::get('openapi.yaml', [DocumentationController::class, 'openapi'])->name('openapi');
    Route::get('postman-collection', [DocumentationController::class, 'postman'])->name('postman-collection');
    Route::get('{slug}.md', [DocumentationController::class, 'markdown'])->name('markdown');

    // Spec-driven endpoint group screens (snaps, templates, ...).
    Route::get('{slug}', [DocumentationController::class, 'group'])->name('group');
});

// Machine-readable discovery, both built on the fly. `llms.txt` renders from
// ApiSpec. `skill.md` renders from SnapsbrewServer::WORKFLOW, the same string
// the MCP server sends as its instructions.
Route::get('llms.txt', [DocumentationController::class, 'llms'])->name('llms');
Route::get('skill.md', [DocumentationController::class, 'skill'])->name('skill');

Route::middleware(['allowIframe', 'throttle:embedded-form-view'])->get('embedded-form/{formUuid}', [EmbeddedFormController::class, 'show'])->name('signedEmbeddedForm');

<?php

use App\Http\Controllers\CreativePackageMediaController;
use App\Http\Middleware\RejectNonScalarSignature;
use Illuminate\Support\Facades\Route;

// Signed bearer URLs for package media. The signature is the credential, so
// there is no auth guard and no session. The file lives outside routes/api.php
// because these URLs are not documented endpoints and are absent from ApiSpec.
// Two distinct names keep a file signature and a preview signature from
// validating on each other. Throttle runs first, so a rejected signature
// counts against the limit (B-066).
Route::middleware(['throttle:api', RejectNonScalarSignature::class, 'signed'])->group(function () {
    Route::get('creative-packages/{package}/items/{item}/file', [CreativePackageMediaController::class, 'file'])->name('creative-packages.file');
    Route::get('creative-packages/{package}/items/{item}/preview', [CreativePackageMediaController::class, 'preview'])->name('creative-packages.preview');
});

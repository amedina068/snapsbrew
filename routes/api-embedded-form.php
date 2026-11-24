<?php

use App\Http\Controllers\Api\V1\EmbeddedFormController;

// The throttle runs first, so a request with a bad signature also counts, and
// a request over the limit writes no usage row and renders nothing.
Route::middleware(['throttle:embedded-form-submit', 'embeddedFormMiddleware'])
    ->post('v1/embedded-forms/{formUuid}', [EmbeddedFormController::class, 'store'])
    ->name('submitSignedEmbeddedForm');

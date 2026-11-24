<?php

use App\Models\Company;
use App\Models\Template;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment(['local', 'testing'])) {
    throw new RuntimeException('Editor browser fixtures may only run in local or testing environments.');
}

$company = Company::query()->firstOrCreate([
    'name' => 'Editor browser smoke company',
]);

$email = 'editor-browser-smoke@example.test';
$password = 'editor-browser-smoke-password';

$user = User::query()->updateOrCreate([
    'email' => $email,
], [
    'name' => 'Editor browser smoke user',
    'password' => Hash::make($password),
    'company_id' => $company->id,
    'email_verified_at' => now(),
    'onboarding_completed_at' => now(),
]);

$template = Template::query()->updateOrCreate([
    'company_id' => $company->id,
    'title' => 'Editor browser smoke',
], [
    'user_id' => $user->id,
    'width' => 600,
    'height' => 380,
    'size' => 'custom',
    'layers' => json_encode([]),
    'groups' => json_encode([]),
]);

// A template with a v2 editor document. The API refuses a legacy save of it
// with 409, the state that a document write or the backfill leaves.
$v2Template = Template::query()->updateOrCreate([
    'company_id' => $company->id,
    'title' => 'Editor browser v2 save',
], [
    'user_id' => $user->id,
    'width' => 600,
    'height' => 380,
    'size' => 'custom',
    'layers' => json_encode([]),
    'groups' => json_encode([]),
]);
$v2Template->forceFill(['editor_schema_version' => 2])->save();

echo json_encode([
    'email' => $email,
    'password' => $password,
    'template_uuid' => $template->uuid,
    'v2_template_uuid' => $v2Template->uuid,
], JSON_THROW_ON_ERROR);

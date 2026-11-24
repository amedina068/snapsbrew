<?php

namespace App\Actions\Instance;

use App\Models\ApiKey;
use App\Models\ApiLog;
use App\Models\Company;
use App\Models\EditorAsset;
use App\Models\Font;
use App\Models\SnapImport;
use App\Models\User;
use App\Support\SignedUrlKeys;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Spatie\Activitylog\Models\Activity;

/**
 * Deletes a user and the personal data of the user, for a privacy request.
 *
 * The templates, snaps and forms belong to the company, so they stay while
 * another member uses them. When the user is the last member, the company
 * and all of its content go too. Models are deleted one at a time, so that
 * the media library also deletes their files.
 */
class DeleteUser
{
    public function delete(User $user): void
    {
        $company = $user->company;
        $lastMember = $company && $company->users()->count() === 1;

        $this->deletePersonalData($user);

        $user->delete();

        if ($lastMember) {
            $this->deleteCompany($company);
        }
    }

    private function deletePersonalData(User $user): void
    {
        $user->tokens()->delete();
        ApiKey::query()->where('user_id', $user->id)->delete();
        SignedUrlKeys::forget($user->company_id);

        $accessTokenIds = Passport::token()->newQuery()->where('user_id', $user->id)->pluck('id');
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $accessTokenIds)->delete();
        Passport::token()->newQuery()->whereKey($accessTokenIds)->delete();
        Passport::authCode()->newQuery()->where('user_id', $user->id)->delete();

        // The usage log and the sessions hold the IP address of the user.
        ApiLog::query()->where('user_id', $user->id)->delete();
        Activity::query()->causedBy($user)->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $user->screenshots()->lazyById()->each->delete();
    }

    /**
     * The database cascade removes the rows that hold no file: tags, signed
     * URL keys, creative packages, brand tokens, idempotency keys and
     * invitations.
     */
    private function deleteCompany(Company $company): void
    {
        $company->templates()->lazyById()->each->delete();
        $company->snaps()->lazyById()->each->delete();
        $company->templateCollections()->lazyById()->each->delete();
        $company->screenshots()->lazyById()->each->delete();
        $company->forms()->delete();
        SnapImport::query()->where('company_id', $company->id)->delete();
        Font::query()->where('company_id', $company->id)->lazyById()->each->delete();
        EditorAsset::query()->where('company_id', $company->id)->lazyById()->each->delete();

        $company->delete();
    }
}

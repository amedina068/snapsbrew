<?php

namespace App\Models;

use App\Support\SignedUrlKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens;
    use HasFactory;
    use InteractsWithMedia;
    use Notifiable;
    use TwoFactorAuthenticatable;

    const ROLE_ADMIN = 'admin';

    const ROLE_CUSTOMER = 'customer';

    /** The role in the company. An owner can change the company itself. */
    const COMPANY_ROLE_OWNER = 'owner';

    /** The role in the company. A member shares the content of the company. */
    const COMPANY_ROLE_MEMBER = 'member';

    const COMPANY_ROLES = [self::COMPANY_ROLE_OWNER, self::COMPANY_ROLE_MEMBER];

    /** The login form and a closed session show this text to a deactivated user. */
    const DEACTIVATED_MESSAGE = 'This account is deactivated. Ask the owner of this instance to reactivate it.';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'company_id',
        'onboarding_completed_at',
        'onboarding_categories',
        'is_owner',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'two_factor_confirmed_at' => 'datetime',
        'onboarding_completed_at' => 'datetime',
        'is_owner' => 'boolean',
        'deactivated_at' => 'datetime',
        'onboarding_categories' => 'array',
    ];

    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function isCustomer()
    {
        return $this->role == self::ROLE_CUSTOMER;
    }

    /**
     * Capability gate: who may do customer-grade content actions
     * (templates, snaps). Admins act as customers everywhere a customer
     * can act. Keep this separate from isCustomer(), which is a pure role
     * check.
     */
    public function actsAsCustomer()
    {
        return $this->isCustomer() || $this->isAdmin();
    }

    public function hasCompletedOnboarding(): bool
    {
        return ! is_null($this->onboarding_completed_at);
    }

    public function getAccountData()
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    /**
     * A user who creates a company is its owner. A user who joins a company
     * that already has users is a member. Every path that creates a user gets
     * this rule: registration, an invitation, `/setup` and `snapsbrew:install`.
     * The role is not mass assignable, so a request cannot set it.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (isset($user->getAttributes()['company_role'])) {
                return;
            }

            $companyHasUsers = $user->company_id !== null
                && User::query()->where('company_id', $user->company_id)->exists();

            $user->company_role = $companyHasUsers ? self::COMPANY_ROLE_MEMBER : self::COMPANY_ROLE_OWNER;
        });
    }

    /**
     * An owner can rename the company, rotate its secrets, manage the
     * invitations and the fonts, and change the roles of the users.
     * This role is not the owner of the instance (`isOwner()`).
     */
    public function isCompanyOwner(): bool
    {
        return $this->company_role === self::COMPANY_ROLE_OWNER;
    }

    /** The person who runs this instance. Only the owner can open Horizon. */
    public function isOwner(): bool
    {
        return (bool) $this->is_owner;
    }

    /** A deactivated user keeps all data and cannot authenticate. */
    public function isDeactivated(): bool
    {
        return ! is_null($this->deactivated_at);
    }

    public function deactivate(): void
    {
        $this->forceFill(['deactivated_at' => now()])->save();

        SignedUrlKeys::forget($this->company_id);
    }

    public function reactivate(): void
    {
        $this->forceFill(['deactivated_at' => null])->save();

        SignedUrlKeys::forget($this->company_id);
    }

    public function isAdmin()
    {
        return $this->role == self::ROLE_ADMIN;
    }

    public function getNewsletterData()
    {
        return [
            'newsletter' => $this->newsletter,
        ];
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('image')
            ->nonOptimized()
            ->performOnCollections('imagesMedia')
            ->nonQueued();
    }

    public function createSignedUrlToken(): ApiKey
    {
        $token = $this->createToken('signed_url', ['signed_url']);

        return ApiKey::create([
            'token_id' => $token->accessToken->id,
            'type' => ApiKey::TYPE_SIGNED_URL,
            'key' => $token->plainTextToken,
            'user_id' => $this->id,
            'company_id' => $this->company_id,
        ]);
    }

    public function scopeAdmin(Builder $query): void
    {
        $query->where('role', 'admin');
    }
}

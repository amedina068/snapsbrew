<?php

namespace App\Models;

use App\Support\SignedUrlKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
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

    /**
     * Store the email in lowercase. MySQL compares emails without case and
     * SQLite compares them with case, so one stored form finds the same user
     * on both databases.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : Str::lower(trim($value)),
        );
    }

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

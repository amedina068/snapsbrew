<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;
    use HasUuid;

    protected $guarded = [];

    protected $casts = [
        'first_snap_at' => 'datetime',
        'webhook_secret' => 'encrypted',
    ];

    protected $hidden = [
        'webhook_secret',
    ];

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            $company->webhook_secret ??= static::newWebhookSecret();
        });
    }

    public static function newWebhookSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /**
     * Replace the secret at once. A receiver that still checks the old secret
     * rejects the next webhook, so the user updates the receiver right after.
     */
    public function rotateWebhookSecret(): void
    {
        $this->update(['webhook_secret' => static::newWebhookSecret()]);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(Template::class);
    }

    public function templateCollections(): HasMany
    {
        return $this->hasMany(TemplateCollection::class);
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(Screenshot::class);
    }

    public function snaps(): HasMany
    {
        return $this->hasMany(Snap::class);
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function creativePackages(): HasMany
    {
        return $this->hasMany(CreativePackage::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }
}

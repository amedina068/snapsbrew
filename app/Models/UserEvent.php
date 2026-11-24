<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of user-level product moments — onboarding completion,
 * first self-made snap, etc. Used to drive sticky dashboard UI and as a
 * lightweight analytics surface that does not depend on a third party.
 *
 * Insert-only: use UserEvent::record($name, $user, $payload) and never
 * update or delete rows.
 */
class UserEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const ONBOARDING_COMPLETED = 'onboarding_completed';

    public const FIRST_SNAP_CREATED = 'first_snap_created';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Record an event for a user. Always inserts — duplicates are intentional
     * (a user can hit the same moment more than once, e.g. re-running through
     * onboarding after reset). Once-only semantics live at the call site
     * (`existsFor()` guards in the event emitter).
     */
    public static function record(string $name, User $user, array $payload = []): self
    {
        return self::create([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            'name' => $name,
            'payload' => $payload,
        ]);
    }

    public static function existsFor(?int $companyId, string $name): bool
    {
        if (! $companyId) {
            return false;
        }

        return self::query()
            ->where('company_id', $companyId)
            ->where('name', $name)
            ->exists();
    }
}

<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Form extends Model
{
    use HasFactory, HasUuid;

    protected $guarded = [];

    public const SCOPE_TEMPLATE = 'template';

    public const SCOPE_COLLECTION = 'collection';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function formable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isForTemplate(): bool
    {
        return $this->formable_type == Template::class;
    }

    public function isForCollection(): bool
    {
        return $this->formable_type == TemplateCollection::class;
    }

    public function scope(): string
    {
        $envelope = json_decode($this->available_layers, true);

        if (is_array($envelope) && isset($envelope['scope'])) {
            return $envelope['scope'];
        }

        return $this->isForCollection() ? self::SCOPE_COLLECTION : self::SCOPE_TEMPLATE;
    }

    public function configuredLayers(): array
    {
        $envelope = json_decode($this->available_layers, true) ?: [];

        return $envelope['layers'] ?? [];
    }

    public function scopeForFormable(Builder $query, Model $model): void
    {
        $query
            ->where([
                'formable_id' => $model->id,
                'formable_type' => get_class($model),
            ]);
    }
}

<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class TemplateCollection extends Model
{
    use HasFactory, HasUuid;

    protected $guarded = [];

    // A form of a collection renders every template of the collection, so
    // it goes with the collection. The templates stay.
    protected static function booted(): void
    {
        static::deleting(function (TemplateCollection $collection) {
            $collection->forms()->delete();
            $collection->templates()->detach();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function templates(): BelongsToMany
    {
        return $this->belongsToMany(Template::class);
    }

    public function forms(): MorphMany
    {
        return $this->morphMany(Form::class, 'formable');
    }
}

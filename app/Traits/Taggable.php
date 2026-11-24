<?php

namespace App\Traits;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait Taggable
{
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps();
    }

    public function syncTags(array $tagIds): void
    {
        $this->tags()->sync($tagIds);
    }

    public function attachTags(array $tagIds): void
    {
        $timestamp = now();
        $attachData = collect($tagIds)
            ->mapWithKeys(fn ($id) => [$id => ['created_at' => $timestamp, 'updated_at' => $timestamp]])
            ->toArray();

        $this->tags()->syncWithoutDetaching($attachData);
    }
}

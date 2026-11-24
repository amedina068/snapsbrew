<?php

namespace App\Actions\Templates;

use App\Models\Template;
use App\Models\User;

class DuplicateTemplate
{
    public function execute(Template $source, User $user): Template
    {
        $newTemplate = $source->replicate()->fill([
            'user_id' => $user->id,
            'company_id' => $user->company_id,
            // Versions belong to the source template. A copied foreign key
            // would point outside the new template and violate Snap's
            // composite source constraint when the first render is queued.
            'published_version_id' => null,
        ]);

        $newTemplate->save();

        $this->copyPreview($source, $newTemplate);
        $this->copyTags($source, $newTemplate);

        return $newTemplate->fresh();
    }

    private function copyPreview(Template $source, Template $target): void
    {
        $previewUrl = $source->previewImage;

        if ($previewUrl) {
            $target
                ->addMediaFromUrl($previewUrl)
                ->toMediaCollection('preview');
        }
    }

    private function copyTags(Template $source, Template $target): void
    {
        $tagIds = $source->tags()
            ->pluck('tags.id');

        if ($tagIds->isEmpty()) {
            return;
        }

        $timestamp = now();

        $target->tags()->syncWithoutDetaching(
            $tagIds->mapWithKeys(function ($id) use ($timestamp) {
                return [$id => ['created_at' => $timestamp, 'updated_at' => $timestamp]];
            })->toArray()
        );
    }
}

<?php

namespace App\Services;

use App\Jobs\ProcessSnap;
use App\Models\Snap;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Support\Facades\Log;

/**
 * Queues the free, personalized "showcase" snap during onboarding.
 *
 * The snap is created with `is_async = false` so that
 * UsageRecorder::recordCompletedAsyncSnap() skips it — onboarding samples
 * stay out of the usage log of a new user. Rendering is dispatched
 * `afterResponse()` so the wizard request returns instantly and a render
 * failure can never bubble up and break onboarding.
 */
class OnboardingSnapDispatcher
{
    public const SHOWCASE_TITLE = '[Onboarding] Showcase';

    /**
     * Category key => showcase layer-key prefix. Layers whose key starts with
     * the prefix of an UNPICKED category are hidden from the render.
     */
    public const CATEGORY_PREFIX = [
        'social' => 'social_',
        'blog' => 'blog_',
        'certificate' => 'cert_',
        'marketing' => 'ad_',
    ];

    public function __construct(private SnapSourceVersioner $sourceVersioner) {}

    /**
     * Queue the showcase snap. Returns the Snap, or null if the showcase
     * template is missing — the feature degrades gracefully (the dashboard
     * just shows its empty state instead).
     */
    public function dispatchShowcase(User $user, array $categories): ?Snap
    {
        try {
            $template = $this->showcaseTemplate();

            if (! $template) {
                Log::warning('Onboarding showcase template not found; skipping showcase snap.');

                return null;
            }

            $variants = $this->showcaseVariants($template, $user, $categories);
            $snap = $this->sourceVersioner->createSnap(
                $template,
                function (Template $lockedTemplate, TemplateVersion $source) use ($user, $variants) {
                    return $lockedTemplate->snaps()->create([
                        'user_id' => $user->id,
                        'company_id' => $user->company_id,
                        'source_template_version_id' => $source->id,
                        'variants' => json_encode($variants),
                        'type' => Snap::TYPE_IMAGE,
                        'is_async' => false,
                        'status' => Snap::STATUS_PENDING,
                        'metadata' => ['onboarding' => 'showcase'],
                    ]);
                }
            );

            // afterResponse: render once the wizard request has already been
            // sent, so it stays fast and a render error cannot break onboarding.
            ProcessSnap::dispatch($snap)->onQueue(Queues::RENDERS)->afterResponse();

            return $snap;
        } catch (\Throwable $e) {
            // The showcase is a nice-to-have — never let it break the wizard.
            Log::warning('Onboarding showcase dispatch failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Whether this user already has a showcase snap — guards against the
     * wizard re-submitting step 2 and queueing duplicates.
     */
    public function hasShowcaseSnap(User $user): bool
    {
        return Snap::query()
            ->where('company_id', $user->company_id)
            ->where('metadata->onboarding', 'showcase')
            ->exists();
    }

    /**
     * Drop showcase snaps that have not finished rendering yet. Used when
     * the user re-picks categories so the next dispatch reflects the new
     * selection. Completed showcases stay so the user does not lose a
     * sample they already saw — a duplicate is acceptable in that case.
     */
    public function discardPendingShowcase(User $user): void
    {
        Snap::query()
            ->where('company_id', $user->company_id)
            ->where('metadata->onboarding', 'showcase')
            ->whereIn('status', [Snap::STATUS_PENDING, Snap::STATUS_PROCESSING])
            ->get()
            ->each(fn (Snap $snap) => $snap->delete());
    }

    private function showcaseTemplate(): ?Template
    {
        return Template::query()
            ->whereNull('user_id')
            ->where('title', self::SHOWCASE_TITLE)
            ->first();
    }

    /**
     * Build variants: company-name text overwrite + `hidden: true` for every
     * layer belonging to a category the user did not pick.
     *
     * @return array<int, array<string, mixed>>
     */
    private function showcaseVariants(Template $template, User $user, array $categories): array
    {
        $variants = [[
            'layer' => 'company_name',
            'text' => $user->company->name,
        ]];

        $unpickedPrefixes = collect(self::CATEGORY_PREFIX)
            ->reject(fn ($prefix, $category) => in_array($category, $categories, true))
            ->values();

        foreach (json_decode($template->layers, true) ?: [] as $layer) {
            $key = $layer['key'] ?? '';

            if ($unpickedPrefixes->contains(fn ($prefix) => str_starts_with($key, $prefix))) {
                $variants[] = ['layer' => $key, 'hidden' => true];
            }
        }

        return $variants;
    }
}

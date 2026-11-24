<?php

namespace App\Listeners;

use App\Events\FirstSnapCreated;
use App\Events\OnboardingCompleted;
use App\Models\UserEvent;

/**
 * Persists product-moment events into the `user_events` log. Stays
 * synchronous on purpose — UserEvent::record() is one indexed query plus
 * one insert, so a queue worker would be more weight than the work.
 */
class RecordUserEvent
{
    public function onOnboardingCompleted(OnboardingCompleted $event): void
    {
        UserEvent::record(UserEvent::ONBOARDING_COMPLETED, $event->user);
    }

    public function onFirstSnapCreated(FirstSnapCreated $event): void
    {
        UserEvent::record(UserEvent::FIRST_SNAP_CREATED, $event->user, [
            'snap_uuid' => $event->snap->uuid,
            'template_uuid' => optional($event->snap->template)->uuid,
        ]);
    }

    public function subscribe($events): array
    {
        return [
            OnboardingCompleted::class => 'onOnboardingCompleted',
            FirstSnapCreated::class => 'onFirstSnapCreated',
        ];
    }
}

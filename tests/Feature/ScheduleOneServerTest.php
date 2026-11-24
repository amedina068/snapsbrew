<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * withoutOverlapping() stops a second run on one server. With two servers
 * that both run `schedule:run`, each server starts its own copy. onOneServer()
 * takes a lock in the shared cache, so only one server runs the command.
 */
class ScheduleOneServerTest extends TestCase
{
    public function test_every_command_without_overlapping_also_runs_on_one_server(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn (Event $event) => $event->withoutOverlapping);

        $this->assertCount(4, $events);

        $events->each(fn (Event $event) => $this->assertTrue($event->onOneServer, "{$event->command} runs on every server."));
    }
}

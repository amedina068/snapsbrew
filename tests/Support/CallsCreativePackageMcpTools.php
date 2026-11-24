<?php

namespace Tests\Support;

use App\Http\Middleware\EnsureMcpAccess;
use App\Models\User;
use App\Support\Queues;
use Illuminate\Testing\TestResponse;

/**
 * The four steps every creative package journey over `/mcp` repeats: mint a
 * token with the MCP ability, forget the caller of the last request, call
 * one tool as a fresh caller, and drain the `imports` queue.
 *
 * Three test classes held their own copy of these methods. This trait is the
 * one place a change to the guard, the ability or the queue lands.
 */
trait CallsCreativePackageMcpTools
{
    private function token(User $user): string
    {
        return $user->createToken('test', [EnsureMcpAccess::SANCTUM_ABILITY])->plainTextToken;
    }

    /**
     * The guard caches the user it resolved for the rest of the test, and
     * `withHeader()` persists too. A test that switches tokens must drop both,
     * or the second token reads as the first caller.
     */
    private function forgetTheCaller(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->flushHeaders();
    }

    /** @param array<string, mixed> $arguments */
    private function tool(string $token, string $name, array $arguments): TestResponse
    {
        return $this->forgetTheCaller()->callMcpTool($token, $name, $arguments);
    }

    /** Run the `imports` queue until it is empty, or for `$maxJobs` jobs when that is above zero. */
    private function work(int $maxJobs = 0): void
    {
        // The worker runs inside the PHPUnit process, which sits near the
        // default 128 MB limit late in a full run. Without --memory, the
        // worker stops after one job and the later items stay pending.
        $this->artisan('queue:work', ['--queue' => Queues::IMPORTS, '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1, '--max-jobs' => $maxJobs, '--memory' => 512]);
    }
}

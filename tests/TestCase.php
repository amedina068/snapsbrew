<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

// Declare the resolver names of `App\Rules` before any test runs. PHP binds an
// unqualified function call to the global function the first time that the
// call runs. A test that sends a webhook resolves a host through `SafeUrl`,
// and `SafeLayersTest` later found its DNS spy bypassed. The spy is off by
// default, so the names forward to the real functions.
require_once __DIR__.'/Support/dns_functions.php';

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite does not need the compiled front-end assets.
        $this->withoutVite();

        $this->isolatePublicDisk();
    }

    /**
     * Keep the files that tests write to the `public` disk out of
     * storage/app/public, which holds the media of the local instance.
     *
     * Each process of a parallel run gets its own folder. Every process starts
     * its media ids at 1, so a shared folder let one process delete the files
     * of another. `Storage::fake('public')` still works, because it replaces
     * the disk after this point.
     */
    private function isolatePublicDisk(): void
    {
        $token = ParallelTesting::token();

        config(['filesystems.disks.public.root' => storage_path(
            'framework/testing/public'.($token ? "_{$token}" : '')
        )]);
    }

    /**
     * Create one account, so that the instance is set up. While the users
     * table is empty, /, /login and /register go to the /setup page.
     */
    protected function completeInstanceSetup(): void
    {
        User::factory()->create();
    }

    /**
     * Create an empty temporary file and delete it after the test.
     *
     * The upload tests wrote files to the system temp folder and did not
     * delete them. After many runs, those files filled the folder.
     */
    protected function temporaryFile(string $prefix, string $extension = ''): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), $prefix);
        $path = $base.$extension;

        $this->beforeApplicationDestroyed(function () use ($base, $path): void {
            @unlink($base);
            @unlink($path);
        });

        return $path;
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A test that writes to the `public` disk writes under
 * storage/framework/testing, never to storage/app/public.
 *
 * Before, each run left media folders named 1, 2, 3 in storage/app/public of
 * the checkout. Those folders hold the files of the local instance too, so a
 * test run could replace or delete the media of a developer. In a parallel run,
 * every process starts its ids at 1, so two processes wrote and deleted the
 * same folder, and a test failed with "Disk named `public` cannot be accessed".
 */
class TestStorageIsolationTest extends TestCase
{
    public function test_the_public_disk_writes_under_the_testing_folder(): void
    {
        $root = config('filesystems.disks.public.root');

        $this->assertStringStartsWith(storage_path('framework/testing/'), $root);
        $this->assertNotSame(storage_path('app/public'), $root);

        Storage::disk('public')->put('isolation-probe.txt', 'probe');

        $this->assertFileExists($root.'/isolation-probe.txt');
        $this->assertFileDoesNotExist(storage_path('app/public/isolation-probe.txt'));

        Storage::disk('public')->delete('isolation-probe.txt');
    }

    public function test_each_parallel_process_has_its_own_public_root(): void
    {
        // ParaTest gives each process its token in this variable.
        $previous = $_SERVER['TEST_TOKEN'] ?? null;
        $_SERVER['TEST_TOKEN'] = '7';

        try {
            $this->refreshApplication();
            $this->setUp();

            $this->assertSame(storage_path('framework/testing/public_7'), config('filesystems.disks.public.root'));
        } finally {
            if ($previous === null) {
                unset($_SERVER['TEST_TOKEN']);
            } else {
                $_SERVER['TEST_TOKEN'] = $previous;
            }
        }
    }
}

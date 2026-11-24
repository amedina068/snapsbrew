<?php

namespace Tests\Support;

use Laravel\Passport\Passport;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Give a test the RSA key pair that Passport signs and reads tokens with.
 *
 * Only the tests that issue or validate an OAuth token need the keys, so this
 * is a trait and not a step in the base TestCase. CI runs PHPUnit with no
 * `passport:keys` step, so the pair is written on first use. The files land in
 * `storage/`, which `.gitignore` already excludes.
 *
 * The pair is generated in a second process on purpose. Generating it inside
 * the PHPUnit process raises that process's memory high enough that
 * `SnapImportCsvTest` then fails to allocate its 12 MB fixture, and the suite
 * dies at the 128 MB limit. Measured: three keyless runs out of three failed
 * with in-process generation, and three out of three passed with this one.
 * A second process returns its memory to the operating system when it exits.
 */
trait WithPassportKeys
{
    private static bool $passportKeysReady = false;

    protected function setUpWithPassportKeys(): void
    {
        // The pair costs about a second to generate and the files survive
        // between runs, so one process does this check one time.
        if (self::$passportKeysReady) {
            return;
        }

        $private = Passport::keyPath('oauth-private.key');
        $public = Passport::keyPath('oauth-public.key');

        if (! file_exists($private) || ! file_exists($public)) {
            $process = new Process(
                [PHP_BINARY, 'artisan', 'passport:keys', '--force', '--no-interaction'],
                base_path()
            );

            $process->run();

            if (! $process->isSuccessful() || ! file_exists($private)) {
                throw new RuntimeException(
                    'Could not create the Passport keys: '.$process->getErrorOutput()
                );
            }
        }

        self::$passportKeysReady = true;
    }
}

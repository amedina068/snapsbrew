<?php

namespace Tests\Unit\Support;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * A key in `config/creative-packages.php` that no code reads is a control an
 * operator turns for nothing. Every key must reach the code through
 * `CreativePackageContract::limit('<key>'`, which is the one reader that
 * clamps a value to its contract ceiling.
 */
class CreativePackageConfigReadersTest extends TestCase
{
    public function test_every_limit_key_is_read_through_the_contract_limit_reader(): void
    {
        $source = '';
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $source .= $file->getContents();
        }

        $unread = [];
        foreach (array_keys(config('creative-packages.limits')) as $key) {
            if (! str_contains($source, "limit('{$key}'")) {
                $unread[] = $key;
            }
        }

        $this->assertSame([], $unread, 'These config keys have no limit() reader under app/.');
    }
}

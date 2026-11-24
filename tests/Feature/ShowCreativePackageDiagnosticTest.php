<?php

namespace Tests\Feature;

use App\Models\CreativePackageItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The operator read of a hidden diagnostic. The command prints the five
 * fields of `CreativePackageItem::diagnostics()` and nothing else, and it is
 * the only production caller of that accessor.
 */
class ShowCreativePackageDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    private const FINGERPRINT = 'f0e1d2c3b4a5968778695a4b3c2d1e0ff0e1d2c3b4a5968778695a4b3c2d1e0f';

    public function test_it_prints_the_five_diagnostic_fields_and_nothing_else(): void
    {
        $item = $this->failedItem();

        $this->assertSame(0, Artisan::call('creative-packages:diagnostic', ['item' => $item->id]));

        $this->assertSame(implode("\n", [
            'diagnostic_code: creative_package_render_failed',
            'diagnostic_exception_class: RuntimeException',
            'diagnostic_correlation_id: 3f2b9d1e-7c4a-4b8e-9d2f-1a2b3c4d5e6f',
            'diagnostic_message_fingerprint: '.self::FINGERPRINT,
            'diagnostic_expires_at: 2026-10-22 12:00:00',
        ])."\n", Artisan::output());
    }

    public function test_it_finds_the_item_by_uuid_and_prints_an_empty_value_for_a_cleared_field(): void
    {
        $item = CreativePackageItem::factory()->failed()->create(['failure_details' => 'The renderer did not produce an image.']);

        $this->assertSame(0, Artisan::call('creative-packages:diagnostic', ['item' => $item->uuid]));

        $output = Artisan::output();
        $this->assertSame("diagnostic_code: \ndiagnostic_exception_class: \ndiagnostic_correlation_id: \ndiagnostic_message_fingerprint: \ndiagnostic_expires_at: \n", $output);
        $this->assertStringNotContainsString('failure', $output);
    }

    /**
     * MySQL casts a text against an integer column, so `'12a...' = 12` is
     * true there. A uuid that starts with another item's id must not print
     * that other item. SQLite compares by affinity and never matched.
     */
    public function test_a_uuid_that_starts_with_another_items_id_prints_the_item_the_uuid_names(): void
    {
        $a = CreativePackageItem::factory()->failed()->create();
        $a->forceFill(['diagnostic_code' => 'code_of_a'])->save();
        $b = CreativePackageItem::factory()->failed()->create();
        $b->forceFill(['uuid' => str_pad("{$a->id}a", 36, '0'), 'diagnostic_code' => 'code_of_b'])->save();

        $this->assertSame(0, Artisan::call('creative-packages:diagnostic', ['item' => $b->uuid]));

        $this->assertStringContainsString('diagnostic_code: code_of_b', Artisan::output());
        $this->assertStringNotContainsString('code_of_a', Artisan::output());
    }

    public function test_an_unknown_item_fails_without_a_field_line(): void
    {
        $this->artisan('creative-packages:diagnostic', ['item' => 'nope'])->assertExitCode(1)
            ->expectsOutput('No creative package item has the id or uuid nope.')->doesntExpectOutputToContain('diagnostic_code');
    }

    /** T-070: the accessor has one production caller, this command. A second caller is a new read surface for a hidden field. */
    public function test_the_command_is_the_only_production_caller_of_the_accessor(): void
    {
        $callers = [];
        foreach ([app_path(), base_path('routes'), resource_path('views')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() === 'php' && str_contains(file_get_contents($file->getPathname()), '->diagnostics(')) {
                    $callers[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        $this->assertSame(['app/Console/Commands/ShowCreativePackageDiagnostic.php'], $callers);
    }

    private function failedItem(): CreativePackageItem
    {
        $item = CreativePackageItem::factory()->failed()->create(['failure_details' => 'The renderer did not produce an image.']);
        $item->forceFill([
            'diagnostic_code' => 'creative_package_render_failed', 'diagnostic_exception_class' => \RuntimeException::class,
            'diagnostic_correlation_id' => '3f2b9d1e-7c4a-4b8e-9d2f-1a2b3c4d5e6f', 'diagnostic_message_fingerprint' => self::FINGERPRINT,
            'diagnostic_expires_at' => '2026-10-22 12:00:00',
        ])->save();

        return $item;
    }
}

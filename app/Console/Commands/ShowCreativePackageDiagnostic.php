<?php

namespace App\Console\Commands;

use App\Models\CreativePackageItem;
use Illuminate\Console\Command;

/**
 * The operator read of one item diagnostic. It prints the five hidden fields
 * of `CreativePackageItem::diagnostics()` and nothing else: no public failure
 * column, no message, no row. No web surface reads the accessor.
 */
class ShowCreativePackageDiagnostic extends Command
{
    protected $signature = 'creative-packages:diagnostic {item : The id or the uuid of the item}';

    protected $description = 'Print the hidden diagnostic of one creative package item.';

    public function handle(): int
    {
        $key = (string) $this->argument('item');
        // One column per shape. MySQL casts a text against an integer column, so `where id = '12a...'` finds item 12.
        $item = CreativePackageItem::query()->where(ctype_digit($key) ? 'id' : 'uuid', $key)->first();
        if ($item === null) {
            $this->error("No creative package item has the id or uuid {$key}.");

            return self::FAILURE;
        }
        foreach ($item->diagnostics() as $field => $value) {
            $this->line("{$field}: ".($value ?? ''));
        }

        return self::SUCCESS;
    }
}

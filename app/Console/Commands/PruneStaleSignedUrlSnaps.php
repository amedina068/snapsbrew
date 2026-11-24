<?php

namespace App\Console\Commands;

use App\Models\Snap;
use Illuminate\Console\Command;

/**
 * Deletes signed-url snaps that have no image.
 *
 * Before the fix, a failed signed-url render left its snap row behind. The row
 * holds the URL identifier and no image, and the middleware redirected every
 * later request for that URL to the home page. The middleware now ignores such
 * rows, so this command only removes the dead rows.
 *
 * A signed-url snap is a row with an `identifier` and no `snap_import_id`. Batch
 * and import rows also carry an `identifier`, but they always have an import.
 * The age guard keeps a render that is still running out of the result.
 */
class PruneStaleSignedUrlSnaps extends Command
{
    protected $signature = 'snaps:prune-stale-signed-url-snaps
        {--force : Delete the rows. Without this option the command is a dry run.}
        {--older-than=15 : Only rows older than this number of minutes.}';

    protected $description = 'Find signed-url snaps with no image, and delete them with --force.';

    public function handle(): int
    {
        $query = Snap::query()
            ->whereNotNull('identifier')
            ->whereNull('snap_import_id')
            ->where('created_at', '<', now()->subMinutes((int) $this->option('older-than')))
            ->withoutImage();

        $count = (clone $query)->count();

        $this->info("Found {$count} stale signed-url snap(s).");

        if (! $this->option('force')) {
            (clone $query)->limit(50)->get(['id', 'uuid', 'template_id', 'created_at'])
                ->each(fn (Snap $snap) => $this->line("  snap {$snap->id} ({$snap->uuid}) template {$snap->template_id} created {$snap->created_at}"));

            $this->warn('Dry run. Nothing was deleted. Run again with --force to delete.');

            return self::SUCCESS;
        }

        $deleted = 0;

        // Delete through the model so the media library also removes the HTML
        // files of each row.
        $query->chunkById(200, function ($snaps) use (&$deleted) {
            foreach ($snaps as $snap) {
                $snap->delete();
                $deleted++;
            }
        });

        $this->info("Deleted {$deleted} stale signed-url snap(s).");

        return self::SUCCESS;
    }
}

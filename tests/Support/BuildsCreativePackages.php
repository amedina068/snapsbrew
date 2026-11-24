<?php

namespace Tests\Support;

use App\Jobs\ProcessCreativePackageItem;
use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\Template;
use App\Models\TemplateVersion;
use App\Models\User;
use App\Services\CreativePackageClaim;
use App\Services\CreativePackageClaimException;
use App\Services\CreativePackageClaimResult;
use App\Services\CreativePackageDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Shared fixtures for the creative package tests: claim requests, company
 * members, one package with one item, and one fixed clock. The dispatcher double records the transaction level and the
 * persisted state at every dispatch, so a test can prove the seam ran after
 * the commit and never inside it.
 */
trait BuildsCreativePackages
{
    /** @var array<int, array{package_id: int, transaction_level: int, persisted: bool}> */
    private array $dispatches = [];

    /** Every query since recordQueries(), as SQL plus JSON bindings. */
    private array $sql = [];

    private function recordQueries(): void
    {
        DB::listen(fn ($query) => $this->sql[] = $query->sql.' '.json_encode($query->bindings));
    }

    /** Run the item executor job body directly, in process, for one item. */
    private function runItem(CreativePackageItem $item): void
    {
        app()->call([new ProcessCreativePackageItem($item->id), 'handle']);
    }

    /** Queries since recordQueries() that name the table (not a table with the name as a prefix). */
    private function queriesTouching(string $table): int
    {
        return count(array_filter($this->sql, fn (string $query) => preg_match('/\b'.$table.'\b/', str_replace(['`', '"'], '', $query)) === 1));
    }

    private function recordDispatches(): void
    {
        Queue::fake();
        $dispatches = &$this->dispatches;
        $this->app->instance(CreativePackageDispatcher::class, new class($dispatches) extends CreativePackageDispatcher
        {
            public function __construct(private array &$dispatches) {}

            public function dispatch(CreativePackage $package): void
            {
                $this->dispatches[] = ['package_id' => $package->id, 'transaction_level' => DB::transactionLevel(), 'persisted' => CreativePackage::query()->whereKey($package->id)->exists()];
            }
        });
    }

    private function claim(User $actor, array $request): CreativePackageClaimResult
    {
        return app(CreativePackageClaim::class)->claim($actor, $request, $this->now());
    }

    private function rejected(User $actor, array $request): CreativePackageClaimException
    {
        try {
            $this->claim($actor, $request);
        } catch (CreativePackageClaimException $exception) {
            return $exception;
        }
        $this->fail('The claim was accepted.');
    }

    /** @param array<int, TemplateVersion> $sources */
    private function request(string $key, array $sources): array
    {
        $items = [];
        foreach (array_values($sources) as $index => $source) {
            $items[] = ['client_creative_id' => "creative-{$index}", 'template_id' => $source->template->uuid, 'template_version' => $source->version_number, 'label' => "Creative {$index}", 'alt_text' => 'A branded image', 'format' => 'png']
                + ($index === 0 ? ['client_family_id' => 'family-0', 'placement_key' => 'instagram.feed.organic', 'caption' => 'Launch day', 'metadata' => ['campaign' => 'launch']] : []);
        }

        return ['idempotency_key' => $key, 'items' => $items];
    }

    private function member(): User
    {
        return $this->memberOf(Company::factory()->create());
    }

    private function memberOf(Company $company, string $role = User::ROLE_CUSTOMER): User
    {
        return User::factory()->for($company)->create(['role' => $role]);
    }

    private function source(User $actor, int $version): TemplateVersion
    {
        $template = Template::factory()->for($actor->company)->for($actor)->create();

        return TemplateVersion::factory()->for($template)->create(['version_number' => $version, 'created_by' => $actor->id, 'published' => true]);
    }

    /** A published source the 20x20 fake renderer satisfies. */
    private function source20(User $actor): TemplateVersion
    {
        $template = Template::factory()->for($actor->company)->for($actor)->create();

        return TemplateVersion::factory()->for($template)->create(['version_number' => 1, 'created_by' => $actor->id, 'published' => true, 'width' => 20, 'height' => 20]);
    }

    /** One company, one package, and one item. */
    private function oneItem(): CreativePackageItem
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $package = CreativePackage::factory()->create(['company_id' => $company->id, 'created_by' => $user->id]);

        return CreativePackageItem::factory()->create(['company_id' => $company->id, 'creative_package_id' => $package->id]);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-09-19 12:00:00');
    }

    private function assertClaimed(int $packages, int $items, int $dispatches): void
    {
        $this->assertSame([$packages, $items, $dispatches], [CreativePackage::count(), CreativePackageItem::count(), count($this->dispatches)]);
    }
}

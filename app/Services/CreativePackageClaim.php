<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CreativePackage;
use App\Models\CreativePackageItem;
use App\Models\User;
use App\Support\CreativePackageContract;
use App\Support\CreativePackageDiagnostic;
use App\Support\CreativePackageRequestValidator;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Claims one creative package: validate, preflight sources, fingerprint, then
 * one short transaction under the company lock that rechecks the key, enforces
 * membership, entitlement and the active limit, and writes the package with
 * every item. The dispatcher runs after the commit.
 *
 * A same-key replay returns the stored package before any authorization check,
 * so it stays readable after the member leaves the company. A replay dispatches only in a
 * second step after the commit, and only for an entitled member: the
 * reconciler reopens and redispatches every reclaimable item of the package.
 */
class CreativePackageClaim
{
    public const OPERATION_NAMESPACE = 'creative_package_export_v1';

    public function __construct(
        private readonly CreativePackageRequestValidator $validator,
        private readonly CreativePackageSourcePreflight $preflight,
        private readonly CreativePackageDispatcher $dispatcher,
        private readonly CreativePackageReconciler $reconciler,
    ) {}

    public function claim(User $actor, array $request, ?CarbonInterface $now = null): CreativePackageClaimResult
    {
        $now ??= now();
        $companyId = $actor->company_id ?? throw $this->entitlementRequired();

        try {
            $request = $this->validator->normalize($request);
        } catch (ValidationException $exception) {
            throw new CreativePackageClaimException('creative_package_invalid_request', 'The creative package request is invalid.', ['fields' => array_keys($exception->errors())]);
        }
        try {
            $sources = $this->preflight->preflight($companyId, $request['items']);
        } catch (CreativePackageSourcePreflightException $exception) {
            // The exception keeps only the detail keys its code allows.
            throw new CreativePackageClaimException($exception->codeName, 'One or more sources cannot be exported.', ['issues' => $exception->details, 'item_index' => array_column($exception->details, 'item_index'), 'client_creative_id' => array_column($exception->details, 'client_creative_id')]);
        }
        $fingerprint = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        $result = DB::transaction(function () use ($actor, $companyId, $request, $sources, $fingerprint, $now): CreativePackageClaimResult {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            if ($existing = $this->existing($company, $request['idempotency_key'], $fingerprint)) {
                return $existing;
            }

            $member = User::query()->whereKey($actor->id)->where('company_id', $company->id)->first();
            if ($member === null || ! self::entitled($member, $company)) {
                throw $this->entitlementRequired();
            }
            $active = CreativePackage::query()->where('company_id', $company->id)
                ->where('status', CreativePackage::STATUS_BUILDING)->where('lease_expires_at', '>', $now)->count();
            if ($active >= CreativePackageContract::limit('active_per_company', CreativePackageContract::MAX_ACTIVE_PACKAGES)) {
                throw new CreativePackageClaimException('creative_package_rate_limited', 'Your company already has the maximum number of packages building.', ['poll_after_seconds' => CreativePackageContract::limit('heartbeat_seconds', CreativePackageContract::HEARTBEAT_SECONDS)]);
            }

            try {
                $package = CreativePackage::query()->create($this->attributes($company, $member, $request, $fingerprint, $now));
            } catch (UniqueConstraintViolationException $exception) {
                // Another claim won the unique claim key after the lookup. Read the winner one time.
                return $this->existing($company, $request['idempotency_key'], $fingerprint) ?? throw $exception;
            }
            foreach ($request['items'] as $position => $item) {
                $source = $sources[$position];
                CreativePackageItem::query()->create(array_intersect_key($item, array_flip(['client_creative_id', 'client_family_id', 'label', 'placement_key', 'caption', 'alt_text', 'metadata'])) + [
                    'company_id' => $company->id, 'creative_package_id' => $package->id, 'position' => $position,
                    'source_template_uuid' => $item['template_id'], 'source_template_version' => $source->version_number,
                    'source_editor_document_version' => $source->editor_schema_version, 'source_template_version_id' => $source->id,
                    'requested_format' => $item['format'], 'status' => CreativePackageItem::STATUS_PENDING,
                ]);
            }
            DB::afterCommit(fn () => $this->dispatcher->dispatch($package));

            return new CreativePackageClaimResult($package, false);
        });
        Log::info('creative_package.claimed', ['package_id' => $result->package->id, 'company_id' => $result->package->company_id, 'replayed' => $result->replayed, 'status' => $result->package->status, 'items' => (int) $result->package->total_items]);
        if ($result->replayed && $this->authorized($actor, $result->package)) {
            try {
                $this->reconciler->resume($result->package, $now);
            } catch (\Throwable $exception) {
                // The read succeeded. A failed redispatch is the reconciler's to repeat, not the caller's to see.
                Log::warning('creative_package.resume_failed', ['package_id' => $result->package->id, 'exception_class' => CreativePackageDiagnostic::className($exception)]);
            }
        }

        return $result;
    }

    /**
     * The replay authorization: the same membership and entitlement a new claim
     * needs. It checks the replaying actor by design. The worker rechecks the
     * package creator before every render.
     */
    private function authorized(User $actor, CreativePackage $package): bool
    {
        $member = User::query()->whereKey($actor->id)->where('company_id', $package->company_id)->first();

        return $member !== null && self::entitled($member, $package->company);
    }

    /** A customer or admin member of the company can export. */
    public static function entitled(User $member, Company $company): bool
    {
        return $member->actsAsCustomer() && $member->company_id === $company->id;
    }

    /** The stored package for this key: a replay when the fingerprint matches, a conflict when it differs. */
    private function existing(Company $company, string $key, string $fingerprint): ?CreativePackageClaimResult
    {
        $existing = CreativePackage::query()->where('company_id', $company->id)
            ->where('operation_namespace', self::OPERATION_NAMESPACE)->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($existing && $existing->request_fingerprint !== $fingerprint) {
            throw new CreativePackageClaimException('creative_package_idempotency_conflict', 'This idempotency_key already holds a package built from a different request.', ['idempotency_key' => $existing->idempotency_key, 'package_id' => $existing->uuid]);
        }

        return $existing ? new CreativePackageClaimResult($existing, true) : null;
    }

    /** @return array<string, mixed> */
    private function attributes(Company $company, User $member, array $request, string $fingerprint, CarbonInterface $now): array
    {
        return [
            'company_id' => $company->id, 'created_by' => $member->id,
            'operation_namespace' => self::OPERATION_NAMESPACE, 'idempotency_key' => $request['idempotency_key'],
            'request_fingerprint' => $fingerprint, 'status' => CreativePackage::STATUS_BUILDING,
            'total_items' => count($request['items']), 'pending_items_count' => count($request['items']),
            'manifest_version' => CreativePackageContract::VERSION, 'started_at' => $now,
            'lease_expires_at' => $now->copy()->addSeconds(CreativePackageContract::limit('lease_seconds', CreativePackageContract::LEASE_SECONDS)),
            'replay_expires_at' => $now->copy()->addDays(CreativePackageContract::limit('replay_days', CreativePackageContract::REPLAY_DAYS)),
        ];
    }

    private function entitlementRequired(): CreativePackageClaimException
    {
        return new CreativePackageClaimException('creative_package_entitlement_required', 'Your account must belong to this company and be allowed to create snaps to export a package.');
    }
}

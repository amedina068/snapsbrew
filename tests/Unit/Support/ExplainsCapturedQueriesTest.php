<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExplainsCapturedQueries;

/** The one access-plan gate of the suite refuses each unsafe plan shape and accepts the intended one. */
class ExplainsCapturedQueriesTest extends TestCase
{
    use ExplainsCapturedQueries;

    private const INDEX = 'api_logs_period_usage_covering';

    #[DataProvider('unsafePlans')]
    public function test_it_rejects_an_unsafe_plan(array $overrides, string $message): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage($message);

        $this->assertAccessPlan(array_merge($this->safePlan(), $overrides), self::INDEX, ['range'], 1_000_000);
    }

    public function test_it_accepts_the_intended_plan_and_a_filesort_only_when_allowed(): void
    {
        $this->assertAccessPlan($this->safePlan(), self::INDEX, ['range'], 1_000_000);
        $this->assertAccessPlan($this->safePlan() + ['Extra' => 'Using filesort'], self::INDEX, ['range'], 1_000_000, filesortAllowed: true);
    }

    public static function unsafePlans(): array
    {
        return [
            'wrong key' => [['key' => 'api_logs_usage_log_id_index'], 'must use the named index'],
            'full scan' => [['type' => 'ALL'], 'access type is not accepted'],
            'temporary table' => [['Extra' => 'Using index; Using temporary'], 'temporary table'],
            'filesort' => [['Extra' => 'Using where; Using index; Using filesort'], 'filesort'],
            'above the bound' => [['rows' => 1_000_001], 'above the stated bound'],
        ];
    }

    private function safePlan(): array
    {
        return ['type' => 'range', 'key' => self::INDEX, 'rows' => 12, 'Extra' => 'Using where; Using index'];
    }
}

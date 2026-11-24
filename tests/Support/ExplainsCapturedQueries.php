<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * EXPLAIN of the SQL a real service call runs. The queries are captured with
 * DB::listen, explained with their real bindings, asserted against the T-018
 * access-plan gate, and written to the evidence file the CI artifact stores.
 */
trait ExplainsCapturedQueries
{
    use RequiresMysql;

    /** Every query since recordCapturedQueries(), as [sql, bindings]. */
    private array $capturedQueries = [];

    private function recordCapturedQueries(): void
    {
        $this->capturedQueries = [];
        DB::listen(fn ($query) => $this->capturedQueries[] = [$query->sql, $query->bindings]);
    }

    /**
     * Run the closure one time and EXPLAIN every recorded query whose SQL
     * starts with one of the prefixes. The result is keyed by the argument
     * name of each prefix, so a caller passes them as named arguments and
     * destructures the result. Each entry carries the real SQL, its bindings and one plan
     * row per table the optimizer touched. One run per call matters: a service
     * that mutates rows, like the reconciler, must not run once per prefix.
     *
     * @return array<string, array<int, array{sql: string, bindings: array<int, mixed>, plan: array<int, array<string, mixed>>}>>
     */
    private function explainQueries(\Closure $run, string ...$prefixes): array
    {
        $this->capturedQueries = [];
        $run();
        $explained = array_fill_keys(array_keys($prefixes), []);
        foreach ($this->capturedQueries as [$sql, $bindings]) {
            foreach ($prefixes as $name => $prefix) {
                if (str_starts_with($sql, $prefix)) {
                    $explained[$name][] = $this->explainSql($sql, $bindings);
                }
            }
        }

        return $explained;
    }

    /**
     * EXPLAIN of one hand-written statement, in the entry shape of
     * explainQueries(). A schema test that pins a FORCE INDEX or a raw sum
     * has no service call to capture, so it hands the SQL in directly.
     *
     * @param  array<int, mixed>  $bindings
     * @return array{sql: string, bindings: array<int, mixed>, plan: array<int, array<string, mixed>>}
     */
    private function explainSql(string $sql, array $bindings = []): array
    {
        return ['sql' => $sql, 'bindings' => $bindings, 'plan' => array_map(fn (object $row) => (array) $row, DB::select("EXPLAIN {$sql}", $bindings))];
    }

    /**
     * The access-plan gate of T-018: the named index, a seek or a range, no
     * temporary table, no filesort unless the query orders by the index prefix,
     * and an estimate within the stated bound.
     *
     * @param  array<string, mixed>  $row  one EXPLAIN row
     * @param  array<int, string>  $types  the accepted `type` values
     */
    private function assertAccessPlan(array $row, string $index, array $types, ?int $maxRows, bool $filesortAllowed = false): void
    {
        $extra = strtolower((string) ($row['Extra'] ?? ''));
        $this->assertSame($index, $row['key'] ?? null, 'The query must use the named index. Plan: '.json_encode($row));
        $this->assertContains(strtolower((string) ($row['type'] ?? '')), $types, 'The access type is not accepted. Plan: '.json_encode($row));
        $this->assertStringNotContainsString('using temporary', $extra, 'The query must not use a temporary table. Plan: '.json_encode($row));
        if (! $filesortAllowed) {
            $this->assertStringNotContainsString('using filesort', $extra, 'The query must not use filesort. Plan: '.json_encode($row));
        }
        if ($maxRows !== null) {
            $this->assertLessThanOrEqual($maxRows, (int) ($row['rows'] ?? PHP_INT_MAX), 'The estimate is above the stated bound. Plan: '.json_encode($row));
        }
    }

    /** One line per plan row for the evidence document: index, type, rows, Extra. */
    private function recordPlanEvidence(string $name, array $explained): void
    {
        $path = storage_path('test-results/creative-package-access-plans.json');
        $evidence = file_exists($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
        $evidence[$name] = array_map(fn (array $entry) => ['sql' => $entry['sql'], 'plan' => array_map(fn (array $row) => ['table' => $row['table'] ?? null, 'key' => $row['key'] ?? null, 'type' => $row['type'] ?? null, 'rows' => $row['rows'] ?? null, 'Extra' => $row['Extra'] ?? null], $entry['plan'])], $explained);
        $this->writeEvidence('creative-package-access-plans.json', $evidence);
    }

    /** One evidence document under storage/test-results, written only when the CI job made that directory. */
    private function writeEvidence(string $file, array $evidence): void
    {
        $path = storage_path("test-results/{$file}");
        if (! is_dir(dirname($path))) {
            return;
        }
        file_put_contents($path, json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}

<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Deposit;
use App\Models\MealEntry;
use App\Models\MemberPayment;
use App\Models\Student;
use App\Models\Transaction;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DYNAMIC REPORTS BUILDER - the query engine.
 *
 * The old reports were fixed screens: a developer had to add a query before an
 * admin could ask a new question. This engine lets a user COMPOSE a report instead:
 *
 *   dataset  : what to look at   (members | deposits | meals | expenses)
 *   metric   : what to measure   (count | sum | average)
 *   measure  : the column to measure (amount | meals | balance | ...)
 *   group_by : how to slice it   (month | week | day | member | department | category | payee | method | status)
 *   filters  : narrow the rows   (date range, department, member, category, ...)
 *
 * SECURITY - the reason this class exists rather than raw SQL in a controller
 * ---------------------------------------------------------------------------
 * Every dataset, metric, measure, group-by and filter key is resolved against the
 * WHITELISTS below. The user's input selects from a menu; it is never interpolated
 * into SQL. A saved report therefore cannot become an injection vector, and an
 * unknown key fails loudly at validation time rather than producing a broken query.
 *
 * TENANCY: every query runs through the tenant-scoped Eloquent models, so a report
 * can only ever read the active institution's rows.
 */
class ReportBuilder
{
    /**
     * The datasets a report can be built over.
     *
     * Each declares its model, its label, the columns that may be measured / shown,
     * the groupings available, and the filters that make sense.
     */
    public const DATASETS = [
        'members' => [
            'label' => 'Members',
            'model' => Student::class,
            'date_column' => 'created_at',
            'measures' => [
                'records' => 'Number of members',
                'total_meals' => 'Total meals eaten',
                'total_deposits' => 'Total deposited',
                'balance' => 'Current balance',
            ],
            'groupings' => [
                'none' => 'No grouping (one total)',
                'member' => 'By member',
                'department' => 'By department',
                'status' => 'By status',
                'month' => 'By month joined',
            ],
            'filters' => ['date_range', 'department', 'member', 'status'],
        ],
        'deposits' => [
            'label' => 'Deposits',
            'model' => Deposit::class,
            'date_column' => 'created_at',
            'measures' => [
                'records' => 'Number of deposits',
                'amount' => 'Total deposited',
                'average' => 'Average deposit',
            ],
            'groupings' => [
                'none' => 'No grouping (one total)',
                'member' => 'By member',
                'department' => 'By department',
                'method' => 'By payment method',
                'month' => 'By month',
                'week' => 'By week',
                'day' => 'By day',
            ],
            'filters' => ['date_range', 'department', 'member', 'method'],
        ],
        'meals' => [
            'label' => 'Meal entries',
            'model' => MealEntry::class,
            'date_column' => 'date',
            'measures' => [
                'records' => 'Number of entries',
                'meals' => 'Total meals',
                'breakfast' => 'Breakfasts',
                'lunch' => 'Lunches',
                'dinner' => 'Dinners',
                'average' => 'Average meals per entry',
            ],
            'groupings' => [
                'none' => 'No grouping (one total)',
                'member' => 'By member',
                'department' => 'By department',
                'month' => 'By month',
                'week' => 'By week',
                'day' => 'By day',
            ],
            'filters' => ['date_range', 'department', 'member'],
        ],
        'expenses' => [
            'label' => 'Expenses',
            'model' => Transaction::class,
            'date_column' => 'created_at',
            // Only expense rows are in scope, whatever the filters say.
            'base_scope' => 'expenses',
            'measures' => [
                'records' => 'Number of expenses',
                'amount' => 'Total spent',
                'average' => 'Average expense',
            ],
            'groupings' => [
                'none' => 'No grouping (one total)',
                'category' => 'By category',
                'payee' => 'By payee',
                'vendor' => 'By vendor',
                'month' => 'By month',
                'week' => 'By week',
                'day' => 'By day',
            ],
            'filters' => ['date_range', 'category', 'payee'],
        ],
    ];

    /** The three aggregation shapes. */
    public const METRICS = ['sum', 'count', 'average'];

    /** A hard ceiling so a careless "group by day over 10 years" cannot exhaust memory. */
    public const MAX_ROWS = 500;

    /**
     * Validate + normalise a report definition.
     *
     * Returns the cleaned spec, or throws with a readable message naming the
     * offending key. Called on save AND again on run, so a stored definition that
     * predates a whitelist change still fails safely.
     */
    public function validateDefinition(array $definition): array
    {
        $dataset = (string) ($definition['dataset'] ?? '');

        if (! array_key_exists($dataset, self::DATASETS)) {
            throw new \InvalidArgumentException("Unknown dataset: {$dataset}");
        }

        $spec = self::DATASETS[$dataset];

        $metric = strtolower((string) ($definition['metric'] ?? 'sum'));

        if (! in_array($metric, self::METRICS, true)) {
            throw new \InvalidArgumentException("Unknown metric: {$metric}");
        }

        $measure = (string) ($definition['measure'] ?? 'records');

        if (! array_key_exists($measure, $spec['measures'])) {
            throw new \InvalidArgumentException("The measure \"{$measure}\" is not available for {$spec['label']}.");
        }

        $groupBy = (string) ($definition['group_by'] ?? 'none');

        if (! array_key_exists($groupBy, $spec['groupings'])) {
            throw new \InvalidArgumentException("The grouping \"{$groupBy}\" is not available for {$spec['label']}.");
        }

        // Filters: keep ONLY keys this dataset declares.
        $allowedFilters = $spec['filters'];
        $filters = [];

        foreach ((array) ($definition['filters'] ?? []) as $key => $value) {
            if (! in_array($key, $allowedFilters, true) || blank($value)) {
                continue;
            }

            $filters[$key] = $value;
        }

        return [
            'dataset' => $dataset,
            'metric' => $metric,
            'measure' => $measure,
            'group_by' => $groupBy,
            'filters' => $filters,
            'from' => $definition['from'] ?? ($definition['filters']['from'] ?? null),
            'to' => $definition['to'] ?? ($definition['filters']['to'] ?? null),
            'limit' => min((int) ($definition['limit'] ?? 50), self::MAX_ROWS),
        ];
    }

    /**
     * RUN a definition and return the rows + a summary.
     *
     * @return array{rows:array, total:float, columns:array, spec:array, labels:array}
     */
    public function run(array $definition): array
    {
        $spec = $this->validateDefinition($definition);
        $dataset = self::DATASETS[$spec['dataset']];

        $query = $this->buildQuery($spec, $dataset);

        // No grouping: a single aggregate row.
        if ($spec['group_by'] === 'none') {
            $total = $this->aggregate($query, $spec);

            return [
                'rows' => [[
                    'label' => 'Total',
                    'value' => $total,
                ]],
                'total' => $total,
                'columns' => ['label' => 'Total', 'value' => $this->measureLabel($spec)],
                'spec' => $spec,
                'labels' => ['group' => 'Total', 'value' => $this->measureLabel($spec)],
            ];
        }

        // Grouped: one row per distinct group key.
        $rows = $this->groupedRows($query, $spec, $dataset);

        return [
            'rows' => $rows,
            'total' => array_sum(array_column($rows, 'value')),
            'columns' => ['label' => $dataset['groupings'][$spec['group_by']], 'value' => $this->measureLabel($spec)],
            'spec' => $spec,
            'labels' => [
                'group' => $dataset['groupings'][$spec['group_by']],
                'value' => $this->measureLabel($spec),
            ],
        ];
    }

    /** Apply the dataset scope + every declared filter. */
    protected function buildQuery(array $spec, array $dataset)
    {
        $model = $dataset['model'];
        $query = $model::query();

        // Some datasets are inherently a slice of their table.
        if (($dataset['base_scope'] ?? null) === 'expenses') {
            $query->where('type', 'expense');
        }

        $filters = $spec['filters'];
        $dateColumn = $dataset['date_column'];

        // Date range first: it is the most common narrowing and the cheapest.
        if (filled($spec['from'] ?? null)) {
            $query->where($dateColumn, '>=', Carbon::parse($spec['from'])->startOfDay());
        }

        if (filled($spec['to'] ?? null)) {
            $query->where($dateColumn, '<=', Carbon::parse($spec['to'])->endOfDay());
        }

        if (filled($filters['member'] ?? null)) {
            $query->where('student_id', (int) $filters['member']);
        }

        if (filled($filters['department'] ?? null)) {
            if ($spec['dataset'] === 'members') {
                $query->where('department_id', (int) $filters['department']);
            } else {
                // Deposits / meals reach the department through the member.
                $studentIds = Student::query()
                    ->where('department_id', (int) $filters['department'])
                    ->pluck('id');

                $query->whereIn('student_id', $studentIds);
            }
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (filled($filters['method'] ?? null) && $spec['dataset'] === 'deposits') {
            $query->where('payment_method', $filters['method']);
        }

        if (filled($filters['category'] ?? null) && $spec['dataset'] === 'expenses') {
            $query->where('category', $filters['category']);
        }

        if (filled($filters['payee'] ?? null) && $spec['dataset'] === 'expenses') {
            $query->where('payee', $filters['payee']);
        }

        return $query;
    }

    /** Evaluate a single aggregate over the query. */
    protected function aggregate($query, array $spec): float
    {
        return match ($spec['measure']) {
            'amount' => round((float) (clone $query)->sum('amount'), 2),
            'meals' => (int) (clone $query)->sum(DB::raw('breakfast + lunch + dinner')),
            'breakfast' => (int) (clone $query)->sum('breakfast'),
            'lunch' => (int) (clone $query)->sum('lunch'),
            'dinner' => (int) (clone $query)->sum('dinner'),
            'total_meals' => (int) $query->get()->sum(fn ($student) => $student->total_meals),
            'total_deposits' => round((float) $query->get()->sum(fn ($student) => $student->total_deposits), 2),
            'balance' => round((float) $query->get()->sum(fn ($student) => $student->balance()), 2),
            'average' => round((float) match ($spec['dataset']) {
                'meals' => (clone $query)->get()->avg(fn ($entry) => ($entry->breakfast ?? 0) + ($entry->lunch ?? 0) + ($entry->dinner ?? 0)) ?? 0,
                default => (clone $query)->avg('amount') ?? 0,
            }, 2),
            // 'records' and anything unrecognised fall back to a row count.
            default => (int) $query->count(),
        };
    }

    /** Build one row per distinct group value. */
    protected function groupedRows($query, array $spec, array $dataset): array
    {
        $groupBy = $spec['group_by'];

        // Time groupings are computed in PHP from a date-keyed aggregate, which
        // keeps them portable across SQLite/MySQL (no driver-specific DATE_FORMAT).
        if (in_array($groupBy, ['day', 'week', 'month'], true)) {
            return $this->timeGroupedRows($query, $spec, $dataset, $groupBy);
        }

        // Entity groupings: aggregate per distinct key, then label it.
        $column = match ($groupBy) {
            'member' => 'student_id',
            'department' => 'department_id',
            'method' => 'payment_method',
            'category' => 'category',
            'payee' => 'payee',
            'status' => 'status',
            'vendor' => 'vendor_id',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        $rows = [];

        $keys = (clone $query)->select($column)->distinct()->pluck($column)->filter()->values();

        foreach ($keys as $key) {
            // Re-apply the whole filter set plus this group key.
            $subset = (clone $query)->where($column, $key);
            $value = $this->aggregate($subset, $spec);

            $rows[] = [
                'key' => $key,
                'label' => $this->labelFor($groupBy, $key),
                'value' => $value,
            ];
        }

        // Biggest first: a report is read top-down.
        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        return array_slice($rows, 0, $spec['limit']);
    }

    /** Group by day / week / month using a portable PHP-side bucketing pass. */
    protected function timeGroupedRows($query, array $spec, array $dataset, string $groupBy): array
    {
        $dateColumn = $dataset['date_column'];

        // Pull (date, measure-input) pairs, then bucket them here.
        $records = (clone $query)->get();

        $buckets = [];

        foreach ($records as $record) {
            $date = $record->{$dateColumn};

            if (! $date) {
                continue;
            }

            $date = $date instanceof Carbon ? $date : Carbon::parse($date);

            $key = match ($groupBy) {
                'day' => $date->toDateString(),
                'week' => $date->copy()->startOfWeek()->toDateString(),
                'month' => $date->format('Y-m'),
            };

            $buckets[$key] = ($buckets[$key] ?? 0) + $this->recordValue($record, $spec);
        }

        ksort($buckets);

        $rows = [];

        foreach ($buckets as $key => $value) {
            // An 'average' measure must divide by the bucket's row count, which the
            // accumulation above cannot do on its own.
            if ($spec['measure'] === 'average') {
                $count = $records->filter(function ($record) use ($dateColumn, $key, $groupBy) {
                    $date = $record->{$dateColumn};
                    $date = $date instanceof Carbon ? $date : Carbon::parse($date);

                    return match ($groupBy) {
                        'day' => $date->toDateString(),
                        'week' => $date->copy()->startOfWeek()->toDateString(),
                        'month' => $date->format('Y-m'),
                    } === $key;
                })->count();

                $value = $count > 0 ? round($value / $count, 2) : 0.0;
            }

            $rows[] = [
                'key' => $key,
                'label' => match ($groupBy) {
                    'day' => Carbon::parse($key)->format('j M Y'),
                    'week' => 'Week of '.Carbon::parse($key)->format('j M Y'),
                    'month' => Carbon::parse($key.'-01')->format('F Y'),
                },
                'value' => is_float($value) ? round($value, 2) : (int) $value,
            ];
        }

        return array_slice($rows, 0, $spec['limit']);
    }

    /** The numeric contribution of ONE record to a time bucket. */
    protected function recordValue($record, array $spec): float
    {
        return match ($spec['measure']) {
            'amount' => (float) ($record->amount ?? 0),
            'meals', 'average' => (float) (($record->breakfast ?? 0) + ($record->lunch ?? 0) + ($record->dinner ?? 0)),
            'breakfast' => (float) ($record->breakfast ?? 0),
            'lunch' => (float) ($record->lunch ?? 0),
            'dinner' => (float) ($record->dinner ?? 0),
            default => 1.0,
        };
    }

    /** Turn a group key into a human label. */
    protected function labelFor(string $groupBy, $key): string
    {
        return match ($groupBy) {
            'member' => Student::query()->whereKey($key)->value('name') ?? "Member #{$key}",
            'department' => Department::query()->whereKey($key)->value('name') ?? 'Unassigned',
            'vendor' => Vendor::query()->whereKey($key)->value('name') ?? 'Unknown vendor',
            'method' => MemberPayment::METHODS[$key]['label'] ?? ucfirst((string) $key),
            'category', 'payee' => $key ?: 'Unspecified',
            'status' => ucfirst((string) $key),
            default => (string) $key,
        };
    }

    protected function measureLabel(array $spec): string
    {
        $dataset = self::DATASETS[$spec['dataset']];

        return $dataset['measures'][$spec['measure']] ?? $spec['measure'];
    }

    /** The full catalogue, for the builder UI. */
    public static function catalogue(): array
    {
        return collect(self::DATASETS)
            ->map(fn (array $dataset, string $key) => [
                'value' => $key,
                'label' => $dataset['label'],
                'measures' => collect($dataset['measures'])
                    ->map(fn (string $label, string $measureKey) => ['value' => $measureKey, 'label' => $label])
                    ->values()->all(),
                'groupings' => collect($dataset['groupings'])
                    ->map(fn (string $label, string $groupKey) => ['value' => $groupKey, 'label' => $label])
                    ->values()->all(),
                'filters' => $dataset['filters'],
            ])
            ->values()
            ->all();
    }
}

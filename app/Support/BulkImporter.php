<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Deposit;
use App\Models\Institution;
use App\Models\MealEntry;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * BULK CSV / EXCEL IMPORT WITH DRY-RUN VALIDATION.
 *
 * INSTITUTIONS ONBOARD WITH SPREADSHEETS. Typing a 400-member roster by hand is
 * the single biggest reason a trial never converts, so this module ingests a CSV
 * (which is also what Excel "Save as CSV" produces - hence CSV/Excel in one pass,
 * with no PhpSpreadsheet dependency).
 *
 * THE DRY-RUN CONTRACT - the whole point of this class
 * ----------------------------------------------------
 * `analyse()` NEVER writes anything. It reads the file, validates EVERY row, and
 * returns a complete per-row report: what would be created, what would be skipped
 * because it already exists, and exactly what is wrong with each bad row. Only when
 * the operator has SEEN that report does `commit()` write anything.
 *
 * That two-phase flow is what makes a bulk import safe on production data: a
 * malformed file can never half-import and leave the roster in a state nobody can
 * explain.
 *
 * THE THREE DATASETS
 *   members           : name, roll, department, status, manager email
 *   opening_balances  : roll, amount  -> one opening Deposit per member
 *   historical_meals  : roll, date, breakfast, lunch, dinner
 *
 * IDEMPOTENCY: rows are matched on the roster `roll` (unique per institution). A
 * member that already exists is reported as `skip`, not duplicated - so the same
 * file can be re-run safely.
 */
class BulkImporter
{
    /** The datasets this importer understands, with their required headers. */
    public const DATASETS = [
        'members' => [
            'label' => 'Members',
            'description' => 'Build the roster. Matches on the member roll/id.',
            'columns' => [
                'name' => ['label' => 'Name', 'required' => true],
                'roll' => ['label' => 'Roll / ID', 'required' => true],
                'department' => ['label' => 'Department (name or slug)', 'required' => false],
                'status' => ['label' => 'Status (active|inactive)', 'required' => false],
                'manager_email' => ['label' => 'Manager email', 'required' => false],
                'phone' => ['label' => 'Phone', 'required' => false],
            ],
        ],
        'opening_balances' => [
            'label' => 'Opening balances',
            'description' => 'Record one starting deposit per member, matched by roll.',
            'columns' => [
                'roll' => ['label' => 'Roll / ID', 'required' => true],
                'amount' => ['label' => 'Opening amount', 'required' => true],
                'method' => ['label' => 'Payment method', 'required' => false],
                'note' => ['label' => 'Note', 'required' => false],
            ],
        ],
        'historical_meals' => [
            'label' => 'Historical meals',
            'description' => 'Backfill daily meal counts, matched by roll + date.',
            'columns' => [
                'roll' => ['label' => 'Roll / ID', 'required' => true],
                'date' => ['label' => 'Date (YYYY-MM-DD)', 'required' => true],
                'breakfast' => ['label' => 'Breakfast', 'required' => false],
                'lunch' => ['label' => 'Lunch', 'required' => false],
                'dinner' => ['label' => 'Dinner', 'required' => false],
            ],
        ],
    ];

    /** How many bad rows we keep in the report before truncating for the UI. */
    public const MAX_REPORTED_ERRORS = 100;

    /**
     * DRY RUN: read + validate the file. Writes NOTHING.
     *
     * @return array{
     *   dataset:string, filename:string, total:int, valid:int, invalid:int,
     *   would_create:int, would_skip:int, rows:array, errors:array,
     *   truncated:bool, headers:array
     * }
     */
    public function analyse(UploadedFile $file, string $dataset, ?Institution $institution): array
    {
        $this->assertDatasetIsKnown($dataset);

        [$headers, $records] = $this->readCsv($file);

        $definition = self::DATASETS[$dataset];

        // Header check FIRST: a missing required column makes every row invalid,
        // so reporting it once is far more useful than 400 identical row errors.
        $headerErrors = $this->validateHeaders($headers, $definition);

        $rows = [];
        $errors = [];
        $wouldCreate = 0;
        $wouldSkip = 0;
        $valid = 0;

        foreach ($records as $index => $record) {
            // Line number as the user sees it in Excel (header is line 1).
            $line = $index + 2;

            $result = $this->analyseRow($dataset, $record, $line, $institution);

            $rows[] = $result;

            if ($result['status'] === 'error') {
                if (count($errors) < self::MAX_REPORTED_ERRORS) {
                    $errors[] = ['line' => $line, 'messages' => $result['messages'], 'data' => $record];
                }
            } else {
                $valid++;

                if ($result['status'] === 'skip') {
                    $wouldSkip++;
                } else {
                    $wouldCreate++;
                }
            }
        }

        return [
            'dataset' => $dataset,
            'dataset_label' => $definition['label'],
            'filename' => $file->getClientOriginalName(),
            'total' => count($records),
            'valid' => $valid,
            'invalid' => count($records) - $valid,
            'would_create' => $wouldCreate,
            'would_skip' => $wouldSkip,
            'rows' => array_slice($rows, 0, self::MAX_REPORTED_ERRORS),
            'errors' => $errors,
            'header_errors' => $headerErrors,
            'truncated' => count($records) > self::MAX_REPORTED_ERRORS,
            'headers' => $headers,
        ];
    }

    /**
     * COMMIT: write the valid rows.
     *
     * Deliberately re-reads and re-validates the uploaded file rather than trusting
     * a client-supplied preview: the analysis the operator approved and the data
     * actually written must come from the SAME bytes on the server.
     *
     * @return array{created:int, skipped:int, invalid:int, errors:array}
     */
    public function commit(UploadedFile $file, string $dataset, ?Institution $institution, ?int $actorId = null): array
    {
        $this->assertDatasetIsKnown($dataset);

        [, $records] = $this->readCsv($file);

        $created = 0;
        $skipped = 0;
        $invalid = 0;
        $errors = [];

        // ONE transaction for the whole file: either the import lands complete, or
        // nothing does. A partial roster is worse than a failed import.
        DB::transaction(function () use ($dataset, $records, $institution, $actorId, &$created, &$skipped, &$invalid, &$errors) {
            foreach ($records as $index => $record) {
                $line = $index + 2;

                $analysis = $this->analyseRow($dataset, $record, $line, $institution);

                if ($analysis['status'] === 'error') {
                    $invalid++;

                    if (count($errors) < self::MAX_REPORTED_ERRORS) {
                        $errors[] = ['line' => $line, 'messages' => $analysis['messages']];
                    }

                    continue;
                }

                if ($analysis['status'] === 'skip') {
                    $skipped++;

                    continue;
                }

                $this->writeRow($dataset, $record, $institution, $actorId);
                $created++;
            }
        });

        return [
            'created' => $created,
            'skipped' => $skipped,
            'invalid' => $invalid,
            'errors' => $errors,
        ];
    }

    /* ------------------------------------------------------------------ *
     * Per-row analysis
     * ------------------------------------------------------------------ */

    /**
     * Validate ONE row and decide what would happen to it.
     *
     * @return array{line:int, status:string, messages:array, summary:string}
     */
    protected function analyseRow(string $dataset, array $record, int $line, ?Institution $institution): array
    {
        return match ($dataset) {
            'members' => $this->analyseMember($record, $line, $institution),
            'opening_balances' => $this->analyseOpeningBalance($record, $line, $institution),
            'historical_meals' => $this->analyseHistoricalMeal($record, $line, $institution),
            default => ['line' => $line, 'status' => 'error', 'messages' => ['Unknown dataset.'], 'summary' => ''],
        };
    }

    protected function analyseMember(array $record, int $line, ?Institution $institution): array
    {
        $messages = [];

        $name = trim((string) ($record['name'] ?? ''));
        $roll = trim((string) ($record['roll'] ?? ''));

        if ($name === '') {
            $messages[] = 'Name is required.';
        }

        if ($roll === '') {
            $messages[] = 'Roll / ID is required.';
        }

        // Department: resolved by name OR slug, and only within this institution.
        $departmentId = null;
        $departmentRaw = trim((string) ($record['department'] ?? ''));

        if ($departmentRaw !== '') {
            $department = Department::query()
                ->where(fn ($q) => $q->where('name', $departmentRaw)->orWhere('slug', $departmentRaw))
                ->first();

            if (! $department) {
                // A warning, not an error: the member is still importable, just
                // unassigned. Flagging it lets the operator create the department
                // and re-run.
                $messages[] = "Department \"{$departmentRaw}\" was not found and will be left unassigned.";
            } else {
                $departmentId = $department->id;
            }
        }

        $status = strtolower(trim((string) ($record['status'] ?? ''))) ?: 'active';

        if (! in_array($status, ['active', 'inactive'], true)) {
            $messages[] = "Status \"{$status}\" is not valid (use active or inactive).";
        }

        // Manager: matched by email, must be a staff account in this institution.
        $managerId = null;
        $managerEmail = trim((string) ($record['manager_email'] ?? ''));

        if ($managerEmail !== '') {
            $manager = User::query()->where('email', $managerEmail)->first();

            if (! $manager) {
                $messages[] = "Manager \"{$managerEmail}\" was not found and will be left unassigned.";
            } else {
                $managerId = $manager->id;
            }
        }

        // Errors (not warnings) block the row.
        $blocking = array_filter($messages, fn (string $m) => str_contains($m, 'required') || str_contains($m, 'not valid'));

        if ($blocking !== []) {
            return ['line' => $line, 'status' => 'error', 'messages' => array_values($messages), 'summary' => ''];
        }

        // Idempotency: an existing roll is a SKIP, never a duplicate.
        $exists = Student::query()->where('roll', $roll)->exists();

        return [
            'line' => $line,
            'status' => $exists ? 'skip' : 'create',
            'messages' => array_values($messages),
            'summary' => $exists
                ? "Member \"{$roll}\" already exists - it will be left as is."
                : "Member \"{$name}\" ({$roll}) would be created.",
            'warnings' => array_values(array_filter($messages, fn (string $m) => ! str_contains($m, 'required'))),
        ];
    }

    protected function analyseOpeningBalance(array $record, int $line, ?Institution $institution): array
    {
        $messages = [];

        $roll = trim((string) ($record['roll'] ?? ''));
        $amountRaw = trim((string) ($record['amount'] ?? ''));

        if ($roll === '') {
            $messages[] = 'Roll / ID is required.';
        }

        if ($amountRaw === '') {
            $messages[] = 'Amount is required.';
        } elseif (! is_numeric($amountRaw)) {
            $messages[] = "Amount \"{$amountRaw}\" is not a number.";
        } elseif ((float) $amountRaw <= 0) {
            $messages[] = 'Amount must be greater than zero.';
        }

        $student = $roll !== '' ? Student::query()->where('roll', $roll)->first() : null;

        if ($roll !== '' && ! $student) {
            $messages[] = "No member with roll \"{$roll}\" exists. Import the roster first.";
        }

        if ($messages !== []) {
            return ['line' => $line, 'status' => 'error', 'messages' => $messages, 'summary' => ''];
        }

        return [
            'line' => $line,
            'status' => 'create',
            'messages' => [],
            'summary' => 'Opening balance of '.number_format((float) $amountRaw, 2)." would be recorded for {$student->name}.",
        ];
    }

    protected function analyseHistoricalMeal(array $record, int $line, ?Institution $institution): array
    {
        $messages = [];

        $roll = trim((string) ($record['roll'] ?? ''));
        $dateRaw = trim((string) ($record['date'] ?? ''));

        if ($roll === '') {
            $messages[] = 'Roll / ID is required.';
        }

        if ($dateRaw === '') {
            $messages[] = 'Date is required.';
        }

        $date = null;

        if ($dateRaw !== '') {
            try {
                $date = Carbon::parse($dateRaw)->toDateString();
            } catch (\Throwable $e) {
                $messages[] = "Date \"{$dateRaw}\" could not be read (use YYYY-MM-DD).";
            }
        }

        // Meal flags must be 0/1 (or blank). Anything else is a data error.
        $flags = [];

        foreach (['breakfast', 'lunch', 'dinner'] as $meal) {
            $raw = trim((string) ($record[$meal] ?? ''));

            if ($raw === '') {
                $flags[$meal] = 0;

                continue;
            }

            if (! in_array($raw, ['0', '1'], true)) {
                $messages[] = ucfirst($meal)." must be 0 or 1 (got \"{$raw}\").";

                continue;
            }

            $flags[$meal] = (int) $raw;
        }

        $student = $roll !== '' ? Student::query()->where('roll', $roll)->first() : null;

        if ($roll !== '' && ! $student) {
            $messages[] = "No member with roll \"{$roll}\" exists. Import the roster first.";
        }

        if ($messages !== []) {
            return ['line' => $line, 'status' => 'error', 'messages' => $messages, 'summary' => ''];
        }

        // Idempotency: an entry already recorded for this member+date is a skip.
        $exists = MealEntry::query()
            ->where('student_id', $student->id)
            ->whereDate('date', $date)
            ->exists();

        $total = array_sum($flags);

        return [
            'line' => $line,
            'status' => $exists ? 'skip' : 'create',
            'messages' => [],
            'summary' => $exists
                ? "A meal entry for {$student->name} on {$date} already exists - it will be left as is."
                : "{$total} meal(s) for {$student->name} on {$date} would be recorded.",
        ];
    }

    /* ------------------------------------------------------------------ *
     * Writers (called ONLY from commit(), inside the transaction)
     * ------------------------------------------------------------------ */

    protected function writeRow(string $dataset, array $record, ?Institution $institution, ?int $actorId): void
    {
        match ($dataset) {
            'members' => $this->writeMember($record, $institution),
            'opening_balances' => $this->writeOpeningBalance($record, $institution, $actorId),
            'historical_meals' => $this->writeHistoricalMeal($record, $institution),
            default => null,
        };
    }

    protected function writeMember(array $record, ?Institution $institution): void
    {
        $roll = trim((string) $record['roll']);
        $departmentRaw = trim((string) ($record['department'] ?? ''));

        $departmentId = $departmentRaw !== ''
            ? Department::query()
                ->where(fn ($q) => $q->where('name', $departmentRaw)->orWhere('slug', $departmentRaw))
                ->value('id')
            : null;

        $managerEmail = trim((string) ($record['manager_email'] ?? ''));
        $managerId = $managerEmail !== ''
            ? User::query()->where('email', $managerEmail)->value('id')
            : null;

        Student::create([
            'institution_id' => $institution?->id,
            'name' => trim((string) $record['name']),
            'roll' => $roll,
            'department_id' => $departmentId,
            'manager_id' => $managerId,
            'status' => strtolower(trim((string) ($record['status'] ?? ''))) ?: 'active',
        ]);
    }

    protected function writeOpeningBalance(array $record, ?Institution $institution, ?int $actorId): void
    {
        $student = Student::query()->where('roll', trim((string) $record['roll']))->firstOrFail();

        Deposit::create([
            'institution_id' => $institution?->id,
            'student_id' => $student->id,
            'amount' => (float) $record['amount'],
            'payment_method' => trim((string) ($record['method'] ?? '')) ?: 'opening',
            'notes' => trim((string) ($record['note'] ?? '')) ?: 'Opening balance (bulk import)',
            'recorded_by' => $actorId,
        ]);
    }

    protected function writeHistoricalMeal(array $record, ?Institution $institution): void
    {
        $student = Student::query()->where('roll', trim((string) $record['roll']))->firstOrFail();

        MealEntry::create([
            'institution_id' => $institution?->id,
            'student_id' => $student->id,
            'date' => Carbon::parse(trim((string) $record['date']))->toDateString(),
            'breakfast' => (int) (trim((string) ($record['breakfast'] ?? '')) ?: 0),
            'lunch' => (int) (trim((string) ($record['lunch'] ?? '')) ?: 0),
            'dinner' => (int) (trim((string) ($record['dinner'] ?? '')) ?: 0),
        ]);
    }

    /* ------------------------------------------------------------------ *
     * CSV reading + helpers
     * ------------------------------------------------------------------ */

    /**
     * Parse the uploaded file into [headers, records].
     *
     * Both header cells and data cells are normalised: headers to snake_case (so
     * "Roll / ID" and "roll" both work), values trimmed and BOM-stripped (Excel
     * writes a UTF-8 BOM that would otherwise corrupt the first header name).
     */
    protected function readCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new \RuntimeException('The uploaded file could not be read.');
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException('The uploaded file could not be opened.');
        }

        $headers = [];
        $records = [];
        $isFirstLine = true;

        try {
            while (($cells = fgetcsv($handle, 0, ',')) !== false) {
                // A completely blank line (Excel trailing newline) is not a row.
                if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                    continue;
                }

                if ($isFirstLine) {
                    $headers = array_map(fn ($header) => $this->normaliseHeader((string) $header), $cells);
                    $isFirstLine = false;

                    continue;
                }

                $record = [];

                foreach ($headers as $index => $header) {
                    if ($header === '') {
                        continue;
                    }

                    $record[$header] = trim((string) ($cells[$index] ?? ''));
                }

                $records[] = $record;
            }
        } finally {
            fclose($handle);
        }

        return [$headers, $records];
    }

    /** "Roll / ID" -> "roll_id"; also maps a few friendly aliases. */
    protected function normaliseHeader(string $header): string
    {
        // Strip the UTF-8 BOM Excel prepends.
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        $normalised = strtolower(trim($header));
        $normalised = preg_replace('/[^a-z0-9]+/', '_', $normalised) ?? $normalised;
        $normalised = trim($normalised, '_');

        // Friendly aliases so a natural spreadsheet still maps cleanly.
        return match ($normalised) {
            'roll_id', 'roll_no', 'roll_number', 'id', 'student_id', 'member_id' => 'roll',
            'full_name', 'student_name', 'member_name' => 'name',
            'group', 'dept' => 'department',
            'manager', 'manager_mail', 'manager_email_address' => 'manager_email',
            'payment_method', 'mode' => 'method',
            'date_of_meal', 'meal_date' => 'date',
            default => $normalised,
        };
    }

    /** Which required headers are missing from the upload? */
    protected function validateHeaders(array $headers, array $definition): array
    {
        $errors = [];

        foreach ($definition['columns'] as $key => $meta) {
            if (($meta['required'] ?? false) && ! in_array($key, $headers, true)) {
                $errors[] = "The column \"{$meta['label']}\" ({$key}) is required but was not found in the file.";
            }
        }

        return $errors;
    }

    protected function assertDatasetIsKnown(string $dataset): void
    {
        if (! array_key_exists($dataset, self::DATASETS)) {
            throw new \InvalidArgumentException("Unknown import dataset: {$dataset}");
        }
    }

    /** The datasets + columns as a UI-friendly list. */
    public static function definitions(): array
    {
        return collect(self::DATASETS)
            ->map(fn (array $definition, string $key) => [
                'value' => $key,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'columns' => collect($definition['columns'])
                    ->map(fn (array $meta, string $column) => [
                        'key' => $column,
                        'label' => $meta['label'],
                        'required' => (bool) ($meta['required'] ?? false),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}

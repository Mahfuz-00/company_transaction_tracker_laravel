<?php

namespace App\Support;

use App\Models\Anomaly;
use App\Models\Deposit;
use App\Models\MealEntry;
use App\Models\Student;
use App\Models\Transaction;
use Carbon\Carbon;

/**
 * FINANCIAL & OPERATIONAL ANOMALY DETECTION.
 *
 * A mess account leaks money in ways nobody notices one row at a time: a deposit
 * keyed twice, one member suddenly eating six meals a day, a balance quietly going
 * thousands into the red. This scanner looks for those patterns and writes one
 * finding per problem for an administrator to review.
 *
 * IDEMPOTENCY IS THE DESIGN CONSTRAINT
 * ------------------------------------
 * The scan is meant to run repeatedly (nightly, or on demand). Each finding
 * carries a `fingerprint` - a hash of kind + subject + window - with a UNIQUE index
 * in the database. Running the scan twice therefore UPDATES the existing finding
 * rather than filling the queue with duplicates, and a finding a human already
 * dismissed stays dismissed.
 *
 * DETECTORS
 *   duplicate_deposit    : same member + same amount within a short window
 *   meal_spike           : a member's day far above their own recent baseline
 *   negative_balance     : a member's balance past a configurable threshold
 *   unusual_expense      : a single expense far above its category's norm
 */
class AnomalyDetector
{
    /** A duplicate is two deposits, same member + amount, within this many hours. */
    public const DUPLICATE_WINDOW_HOURS = 48;

    /** How many standard deviations above the mean counts as a meal spike. */
    public const SPIKE_SIGMA = 2.5;

    /** A balance worse than this (negative) is reported. */
    public const NEGATIVE_BALANCE_THRESHOLD = -1000.0;

    /** An expense this many times its category median is "unusual". */
    public const EXPENSE_MULTIPLE = 3.0;

    /**
     * Run every detector for a window and persist the findings.
     *
     * @param  int|null  $institutionId  null = whatever the tenant scope resolves to
     * @return array{findings:int, by_kind:array<string,int>}
     */
    public function scan(?int $institutionId = null, ?Carbon $since = null): array
    {
        // Default window: the last 30 days of activity.
        $since ??= Carbon::now()->subDays(30)->startOfDay();

        $findings = array_merge(
            $this->detectDuplicateDeposits($since),
            $this->detectMealSpikes($since),
            $this->detectNegativeBalances(),
            $this->detectUnusualExpenses($since),
        );

        $counts = [];

        foreach ($findings as $finding) {
            $this->persist($finding, $institutionId);
            $counts[$finding['kind']] = ($counts[$finding['kind']] ?? 0) + 1;
        }

        return ['findings' => count($findings), 'by_kind' => $counts];
    }

    /* ------------------------------------------------------------------ *
     * DETECTORS
     * ------------------------------------------------------------------ */

    /**
     * DUPLICATE DEPOSITS.
     *
     * Two deposits for the SAME member, of the SAME amount, close together in time
     * is almost always a double entry rather than a coincidence - especially in a
     * cash-based mess where a manager re-submits after a page refresh.
     */
    public function detectDuplicateDeposits(Carbon $since): array
    {
        $findings = [];

        $deposits = Deposit::query()
            ->where('created_at', '>=', $since)
            ->whereNull('reversed_at')
            ->orderBy('student_id')
            ->orderBy('created_at')
            ->get(['id', 'student_id', 'amount', 'payment_method', 'created_at', 'notes']);

        // Group by member + amount, then look for pairs close in time. Grouping in
        // PHP keeps this driver-agnostic (no window functions).
        $grouped = $deposits->groupBy(fn (Deposit $d) => $d->student_id.'|'.number_format((float) $d->amount, 2));

        foreach ($grouped as $key => $group) {
            if ($group->count() < 2) {
                continue;
            }

            $ordered = $group->sortBy('created_at')->values();

            for ($i = 1; $i < $ordered->count(); $i++) {
                $previous = $ordered[$i - 1];
                $current = $ordered[$i];

                $hoursApart = $previous->created_at->diffInHours($current->created_at);

                if ($hoursApart > self::DUPLICATE_WINDOW_HOURS) {
                    continue;
                }

                [$studentId, $amount] = explode('|', $key);

                $findings[] = [
                    'kind' => 'duplicate_deposit',
                    'severity' => $hoursApart <= 6 ? 'critical' : 'warning',
                    'subject_type' => Deposit::class,
                    'subject_id' => $current->id,
                    'student_id' => (int) $studentId,
                    'title' => 'Possible duplicate deposit',
                    'detail' => sprintf(
                        'Two deposits of %s were recorded for the same member %d hour(s) apart (%s and %s). This is usually a double entry - reverse one if so.',
                        number_format((float) $current->amount, 2),
                        $hoursApart,
                        $previous->created_at->format('j M Y H:i'),
                        $current->created_at->format('j M Y H:i'),
                    ),
                    'amount' => (float) $current->amount,
                    // The closer in time, the more likely a true duplicate.
                    'score' => round(1 / max($hoursApart, 1), 4),
                    'detected_for' => $current->created_at->toDateString(),
                    'fingerprint' => $this->fingerprint('duplicate_deposit', "{$previous->id}:{$current->id}"),
                ];
            }
        }

        return $findings;
    }

    /**
     * MEAL SPIKES.
     *
     * A member eating far more than THEIR OWN recent baseline is suspicious in a
     * way a fixed threshold is not: a big eater legitimately eats 3 meals a day, so
     * we compare each day against that member's own average and standard deviation
     * (a simple z-score), not against a platform-wide number.
     */
    public function detectMealSpikes(Carbon $since): array
    {
        $findings = [];

        // Pull the window's entries once, grouped per member.
        $entries = MealEntry::query()
            ->where('date', '>=', $since->toDateString())
            ->get(['id', 'student_id', 'date', 'breakfast', 'lunch', 'dinner'])
            ->groupBy('student_id');

        foreach ($entries as $studentId => $memberEntries) {
            // Need a few days before an average means anything.
            if ($memberEntries->count() < 5) {
                continue;
            }

            $dailyTotals = $memberEntries->map(fn (MealEntry $e) => $this->mealTotal($e));

            $mean = $dailyTotals->avg();
            $stdDev = $this->standardDeviation($dailyTotals->all(), $mean);

            /*
             * A PERFECTLY FLAT baseline is the most suspicious case, not one to
             * skip.
             *
             * A member who eats exactly the same amount every day and then doubles
             * it has a standard deviation of ZERO, which makes the z-score
             * mathematically undefined - and the previous `continue` here silently
             * ignored precisely the spike an admin most wants to see.
             *
             * When the deviation is zero we fall back to a RELATIVE test: flag a day
             * that is at least the spike factor times the baseline. With a flat
             * baseline of 1 and a day of 3, that is a genuine spike; a flat 3 that
             * stays 3 is not.
             */
            if ($stdDev <= 0.0) {
                $findings = array_merge(
                    $findings,
                    $this->flatBaselineSpikes($memberEntries, (int) $studentId, $mean),
                );

                continue;
            }

            foreach ($memberEntries as $entry) {
                $total = $this->mealTotal($entry);
                $z = ($total - $mean) / $stdDev;

                if ($z < self::SPIKE_SIGMA) {
                    continue;
                }

                $findings[] = [
                    'kind' => 'meal_spike',
                    'severity' => $z >= self::SPIKE_SIGMA + 1.5 ? 'critical' : 'warning',
                    'subject_type' => MealEntry::class,
                    'subject_id' => $entry->id,
                    'student_id' => (int) $studentId,
                    'title' => 'Unusual meal spike',
                    'detail' => sprintf(
                        '%d meals were recorded on %s, against this member\'s typical %.1f per day. Verify the entry is correct.',
                        $total,
                        $entry->date->format('j M Y'),
                        $mean,
                    ),
                    'score' => round($z, 4),
                    'detected_for' => $entry->date->toDateString(),
                    'fingerprint' => $this->fingerprint('meal_spike', "{$entry->id}"),
                ];
            }
        }

        return $findings;
    }

    /**
     * Flag spikes when a member's baseline has NO variance at all.
     *
     * With a perfectly flat history the z-score is undefined, so we compare the
     * day against a simple multiple of the baseline instead. This is the case that
     * matters most in practice: a member with a stable routine who suddenly has a
     * day recorded at several times their normal intake.
     *
     * @param  \Illuminate\Support\Collection  $memberEntries
     * @return array<int, array>
     */
    protected function flatBaselineSpikes($memberEntries, int $studentId, float $mean): array
    {
        // A zero baseline (a member who has never eaten) cannot be "exceeded" in a
        // meaningful way, so it is left alone.
        if ($mean <= 0.0) {
            return [];
        }

        // The multiple of the flat baseline that counts as a spike. 2.0 means
        // "at least double what this member always eats".
        $threshold = $mean * 2.0;

        $findings = [];

        foreach ($memberEntries as $entry) {
            $total = $this->mealTotal($entry);

            if ($total < $threshold) {
                continue;
            }

            $multiple = $total / $mean;

            $findings[] = [
                'kind' => 'meal_spike',
                'severity' => $multiple >= 3.0 ? 'critical' : 'warning',
                'subject_type' => MealEntry::class,
                'subject_id' => $entry->id,
                'student_id' => $studentId,
                'title' => 'Unusual meal spike',
                'detail' => sprintf(
                    '%d meals were recorded on %s, against a steady baseline of %.1f per day. Verify the entry is correct.',
                    $total,
                    $entry->date->format('j M Y'),
                    $mean,
                ),
                'score' => round($multiple, 4),
                'detected_for' => $entry->date->toDateString(),
                'fingerprint' => $this->fingerprint('meal_spike', (string) $entry->id),
            ];
        }

        return $findings;
    }

    /**
     * NEGATIVE BALANCES.
     *
     * A member far in the red is either an unrecorded payment (the mess is owed
     * money nobody chased) or a data-entry error. Flagging the worst offenders lets
     * an admin resolve it before month end.
     */
    public function detectNegativeBalances(): array
    {
        $findings = [];

        $students = Student::query()
            ->with('department:id,name')
            ->get(['id', 'name', 'roll', 'department_id', 'institution_id']);

        foreach ($students as $student) {
            $balance = $student->balance();

            if ($balance >= self::NEGATIVE_BALANCE_THRESHOLD) {
                continue;
            }

            // Materiality: how far past the threshold, capped so one catastrophic
            // account does not dwarf everything else in the ranking.
            $score = min(abs($balance) / abs(self::NEGATIVE_BALANCE_THRESHOLD), 10.0);

            $findings[] = [
                'kind' => 'negative_balance',
                'severity' => abs($balance) >= abs(self::NEGATIVE_BALANCE_THRESHOLD) * 3 ? 'critical' : 'warning',
                'subject_type' => Student::class,
                'subject_id' => $student->id,
                'student_id' => $student->id,
                'title' => 'Large negative balance',
                'detail' => sprintf(
                    '%s (%s) owes %s. Check for an unrecorded deposit or a mis-keyed amount.',
                    $student->name,
                    $student->roll ?: 'no roll',
                    number_format(abs($balance), 2),
                ),
                'amount' => round($balance, 2),
                'score' => round($score, 4),
                // Balances are a current-state check, not a dated event.
                'detected_for' => now()->toDateString(),
                // Fingerprint on the BUCKET (severity band), so a balance that
                // merely drifts a little does not create a new finding every scan.
                'fingerprint' => $this->fingerprint(
                    'negative_balance',
                    $student->id.':'.(int) floor(abs($balance) / abs(self::NEGATIVE_BALANCE_THRESHOLD))
                ),
            ];
        }

        return $findings;
    }

    /**
     * UNUSUAL EXPENSES.
     *
     * Compared against the MEDIAN of other expenses in the same category (median,
     * not mean, so a previous outlier does not raise the bar for everything after
     * it).
     */
    public function detectUnusualExpenses(Carbon $since): array
    {
        $findings = [];

        $expenses = Transaction::query()
            ->where('type', 'expense')
            ->where('created_at', '>=', $since)
            ->get(['id', 'amount', 'category', 'item', 'payee', 'created_at']);

        foreach ($expenses->groupBy('category') as $category => $group) {
            // Too few data points to know what "normal" is.
            if ($group->count() < 4) {
                continue;
            }

            $median = $this->median($group->pluck('amount')->map(fn ($a) => (float) $a)->all());

            if ($median <= 0.0) {
                continue;
            }

            foreach ($group as $expense) {
                $multiple = (float) $expense->amount / $median;

                if ($multiple < self::EXPENSE_MULTIPLE) {
                    continue;
                }

                $findings[] = [
                    'kind' => 'unusual_expense',
                    'severity' => $multiple >= self::EXPENSE_MULTIPLE * 2 ? 'critical' : 'warning',
                    'subject_type' => Transaction::class,
                    'subject_id' => $expense->id,
                    'student_id' => null,
                    'title' => 'Unusually large expense',
                    'detail' => sprintf(
                        '%s was recorded under "%s" at %s - about %.1f times the usual amount for that category. Confirm it was not keyed incorrectly.',
                        $expense->item ?: 'An expense',
                        $category ?: 'uncategorised',
                        number_format((float) $expense->amount, 2),
                        $multiple,
                    ),
                    'amount' => (float) $expense->amount,
                    'score' => round($multiple, 4),
                    'detected_for' => $expense->created_at->toDateString(),
                    'fingerprint' => $this->fingerprint('unusual_expense', (string) $expense->id),
                ];
            }
        }

        return $findings;
    }

    /* ------------------------------------------------------------------ *
     * Persistence + maths helpers
     * ------------------------------------------------------------------ */

    /**
     * Write (or refresh) a finding.
     *
     * `firstOrNew` on the fingerprint + a manual status guard is deliberate: we
     * UPDATE an open finding's detail/score, but NEVER resurrect one a reviewer has
     * already dismissed - a dismissed false positive must stay dismissed.
     */
    protected function persist(array $finding, ?int $institutionId): Anomaly
    {
        $existing = Anomaly::withoutTenantScope()->where('fingerprint', $finding['fingerprint'])->first();

        if ($existing) {
            // Already actioned by a human: leave it exactly as they left it.
            if (in_array($existing->status, ['dismissed', 'resolved'], true)) {
                return $existing;
            }

            $existing->forceFill([
                'severity' => $finding['severity'],
                'title' => $finding['title'],
                'detail' => $finding['detail'],
                'amount' => $finding['amount'] ?? null,
                'score' => $finding['score'] ?? null,
                'detected_for' => $finding['detected_for'] ?? null,
                'status' => 'open',
            ])->save();

            return $existing;
        }

        return Anomaly::withoutTenantScope()->create([
            'institution_id' => $institutionId,
            'kind' => $finding['kind'],
            'severity' => $finding['severity'],
            'subject_type' => $finding['subject_type'] ?? null,
            'subject_id' => $finding['subject_id'] ?? null,
            'student_id' => $finding['student_id'] ?? null,
            'title' => $finding['title'],
            'detail' => $finding['detail'],
            'amount' => $finding['amount'] ?? null,
            'score' => $finding['score'] ?? null,
            'detected_for' => $finding['detected_for'] ?? null,
            'status' => 'open',
            'fingerprint' => $finding['fingerprint'],
        ]);
    }

    /** A stable hash identifying "this finding about this subject". */
    protected function fingerprint(string $kind, string $subject): string
    {
        return hash('sha256', $kind.'|'.$subject);
    }

    protected function mealTotal(MealEntry $entry): int
    {
        return (int) ($entry->breakfast ?? 0) + (int) ($entry->lunch ?? 0) + (int) ($entry->dinner ?? 0);
    }

    /** Population standard deviation; 0.0 for an empty set. */
    protected function standardDeviation(array $values, float $mean): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $sumSquares = 0.0;

        foreach ($values as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / $count);
    }

    /** Median of a numeric list (0.0 when empty). */
    protected function median(array $values): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        sort($values);

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\EmployeeStatus;
use App\Enums\ShirtMilestoneSource;
use App\Enums\ShirtMilestoneStatus;
use App\Models\Employee;
use App\Models\EmployeeShirtMilestone;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeStore;
use App\Services\ShirtMilestoneWorkflowService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opens a shirt milestone for every completed month of tenure.
 *
 * Milestones are anchored to the employee's CURRENT employment stint — the
 * effective_date of their latest hired/rehired status — so a rehired employee
 * starts earning shirts again a month after coming back.
 */
class GenerateShirtMilestonesCommand extends Command
{
    protected $signature = 'shirts:generate-milestones
        {--since= : Only generate milestones due on or after this date (default: config shirt_milestones.generate_from)}
        {--chunk=500 : Employees per batch}
        {--dry-run : Report what would be created without writing anything}';

    protected $description = 'Open a shirt milestone for each completed month of tenure';

    /**
     * Stops a corrupt stint_start_date (say, year 1900) from spinning the
     * month loop for thousands of iterations.
     */
    private const MAX_MONTHS = 600;

    public function handle(ShirtMilestoneWorkflowService $workflow): int
    {
        $today = CarbonImmutable::today();
        $interval = max(1, (int) config('shirt_milestones.interval_months', 1));

        $since = $this->resolveSince();
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($since === null) {
            $this->warn('No --since and no shirt_milestones.generate_from configured: every past month of every');
            $this->warn('active employee is eligible. On a first run that can be a very large backfill.');
        } else {
            $this->line("Generating milestones due on or after {$since->toDateString()}.");
        }

        $created = 0;
        $skippedNoStore = 0;
        $examined = 0;
        $pending = [];

        Employee::query()
            ->orderBy('id')
            ->chunkById($chunkSize, function (Collection $employees) use (
                $today, $interval, $since, $dryRun, &$created, &$skippedNoStore, &$examined, &$pending
            ) {
                $ids = $employees->pluck('id');
                $examined += $ids->count();

                $stintStarts = $this->currentStintStarts($ids, $today);
                $storeIds = $this->currentStoreIds($ids);
                $existing = $this->existingTuples($ids);

                $rows = [];

                foreach ($employees as $employee) {
                    $stintStart = $stintStarts->get($employee->id);

                    // Not currently employed, or never had a status row.
                    if ($stintStart === null) {
                        continue;
                    }

                    $storeId = $storeIds->get($employee->id);

                    if ($storeId === null) {
                        // Silently generating nothing is how this goes
                        // unnoticed for months, so say so.
                        $skippedNoStore++;
                        $this->warn("Employee {$employee->id} has no store assignment; skipped.");
                        continue;
                    }

                    foreach ($this->dueMilestones($stintStart, $today, $interval, $since) as [$month, $dueDate]) {
                        $key = $employee->id . '|' . $stintStart->toDateString() . '|' . $month;

                        if (isset($existing[$key])) {
                            continue;
                        }

                        $rows[] = [
                            'employee_id' => $employee->id,
                            'store_id' => $storeId,
                            'milestone_month' => $month,
                            'stint_start_date' => $stintStart->toDateString(),
                            'due_date' => $dueDate->toDateString(),
                            'source' => ShirtMilestoneSource::Automatic->value,
                            'status' => ShirtMilestoneStatus::PendingEntry->value,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];

                        $pending[] = $key;
                    }
                }

                if ($rows === []) {
                    return;
                }

                if ($dryRun) {
                    $created += count($rows);

                    foreach ($rows as $row) {
                        $this->line(sprintf(
                            '  would create: employee %d, month %d, due %s',
                            $row['employee_id'],
                            $row['milestone_month'],
                            $row['due_date']
                        ));
                    }

                    return;
                }

                // insertOrIgnore leans on the (employee, stint, month) unique
                // index, so a concurrent run or a re-run costs nothing and
                // needs no SELECT-then-INSERT. It fires no model events and
                // does not set timestamps, which is why they are above.
                EmployeeShirtMilestone::query()->insertOrIgnore($rows);

                $created += count($rows);
            });

        $this->info(sprintf(
            '%s %d milestone(s) across %d employee(s).',
            $dryRun ? 'Would create' : 'Created',
            $created,
            $examined
        ));

        if ($skippedNoStore > 0) {
            $this->warn("Skipped {$skippedNoStore} employee(s) with no store assignment.");
        }

        if (!$dryRun && $pending !== []) {
            $this->notifyCreated($pending, $workflow);
        }

        return self::SUCCESS;
    }

    /**
     * Every milestone month that has come due, as [month, dueDate] pairs.
     *
     * Walks forward rather than using diffInMonths: Carbon 3 returns a signed
     * float there whose direction is easy to get backwards, and walking is
     * exactly consistent with the addMonthsNoOverflow used for the due date.
     *
     * @return list<array{0: int, 1: CarbonImmutable}>
     */
    private function dueMilestones(
        CarbonImmutable $stintStart,
        CarbonImmutable $today,
        int $interval,
        ?CarbonImmutable $since
    ): array {
        $out = [];

        for ($month = $interval; $month <= self::MAX_MONTHS; $month += $interval) {
            // addMonthsNoOverflow, NOT addMonths: PHP's DateTime rolls
            // 2026-01-31 +1 month forward to 2026-03-03, which would put the
            // month-1 milestone in March and stop milestone_month tracking
            // the calendar at all.
            $dueDate = $stintStart->addMonthsNoOverflow($month);

            if ($dueDate->greaterThan($today)) {
                break;
            }

            if ($since !== null && $dueDate->lessThan($since)) {
                continue;
            }

            $out[] = [$month, $dueDate];
        }

        return $out;
    }

    /**
     * Start of each employee's current stint, keyed by employee id. Absent
     * when the employee is not currently employed.
     *
     * Takes the single LATEST status row and requires it to be hired/rehired.
     * Filtering to hired/rehired rows instead — as LaborReportService does —
     * would keep returning a start date for terminated employees forever,
     * because the `hired` row that preceded the termination never goes away.
     *
     * @return Collection<int, CarbonImmutable>
     */
    private function currentStintStarts(Collection $employeeIds, CarbonImmutable $asOf): Collection
    {
        $active = [EmployeeStatus::Hired->value, EmployeeStatus::Rehired->value];

        return EmployeeStatusHistory::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('effective_date', '<=', $asOf->toDateString())
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['employee_id', 'status', 'effective_date'])
            // unique() keeps the first of each group, i.e. the latest row.
            ->unique('employee_id')
            ->filter(fn (EmployeeStatusHistory $row) => in_array(
                $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
                $active,
                true
            ))
            ->mapWithKeys(fn (EmployeeStatusHistory $row) => [
                $row->employee_id => CarbonImmutable::parse($row->effective_date),
            ]);
    }

    /**
     * Current store per employee: latest effective_date, tiebreak highest id —
     * the same rule as Employee::latestStore().
     *
     * @return Collection<int, int>
     */
    private function currentStoreIds(Collection $employeeIds): Collection
    {
        return EmployeeStore::query()
            ->whereIn('employee_id', $employeeIds)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->get(['employee_id', 'store_id'])
            ->unique('employee_id')
            ->mapWithKeys(fn (EmployeeStore $row) => [$row->employee_id => (int) $row->store_id]);
    }

    /**
     * Milestones that already exist for this chunk, as an
     * "employeeId|stintStart|month" lookup.
     *
     * @return array<string, true>
     */
    private function existingTuples(Collection $employeeIds): array
    {
        return DB::table('employee_shirt_milestones')
            ->whereIn('employee_id', $employeeIds)
            ->whereNotNull('milestone_month')
            ->get(['employee_id', 'stint_start_date', 'milestone_month'])
            ->mapWithKeys(fn ($row) => [
                $row->employee_id
                    . '|' . CarbonImmutable::parse($row->stint_start_date)->toDateString()
                    . '|' . $row->milestone_month => true,
            ])
            ->all();
    }

    /**
     * Tell each store's entry role about the milestones just opened.
     *
     * Re-queries rather than reusing the insert payload because insertOrIgnore
     * hands back no ids, and the notification needs one for its action_url.
     *
     * @param list<string> $keys
     */
    private function notifyCreated(array $keys, ShirtMilestoneWorkflowService $workflow): void
    {
        $byEmployee = [];

        foreach ($keys as $key) {
            [$employeeId, $stintStart, $month] = explode('|', $key);
            $byEmployee[(int) $employeeId][] = [$stintStart, (int) $month];
        }

        EmployeeShirtMilestone::query()
            ->with(['employee', 'store'])
            ->whereIn('employee_id', array_keys($byEmployee))
            ->where('status', ShirtMilestoneStatus::PendingEntry)
            ->where('source', ShirtMilestoneSource::Automatic)
            ->orderBy('id')
            ->chunk(200, function (Collection $milestones) use ($byEmployee, $workflow) {
                foreach ($milestones as $milestone) {
                    $wanted = $byEmployee[$milestone->employee_id] ?? [];

                    $isNew = collect($wanted)->contains(
                        fn (array $pair) => $pair[0] === $milestone->stint_start_date?->toDateString()
                            && $pair[1] === (int) $milestone->milestone_month
                    );

                    if (!$isNew) {
                        continue;
                    }

                    try {
                        $workflow->notifyMilestoneOpened($milestone);
                    } catch (\Throwable $e) {
                        // One bad notification must not cost the whole run.
                        $this->error("Failed to notify for milestone {$milestone->id}: {$e->getMessage()}");
                    }
                }
            });
    }

    private function resolveSince(): ?CarbonImmutable
    {
        $raw = $this->option('since') ?: config('shirt_milestones.generate_from');

        if (blank($raw)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->startOfDay();
        } catch (\Throwable) {
            $this->error("Could not parse --since value '{$raw}'; expected YYYY-MM-DD.");

            return null;
        }
    }
}

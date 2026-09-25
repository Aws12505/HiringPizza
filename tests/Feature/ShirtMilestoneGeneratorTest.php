<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeShirtMilestone;
use App\Models\ShirtColor;
use App\Models\ShirtLogo;
use App\Models\Store;
use App\Services\ShirtMilestoneWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the two things about the generator that are silent when wrong:
 * month arithmetic around short months, and idempotency.
 */
class ShirtMilestoneGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shirt_milestones.generate_from', null);
        config()->set('shirt_milestones.interval_months', 1);
    }

    public function test_month_end_hire_dates_do_not_overflow_into_the_next_month(): void
    {
        // PHP's DateTime rolls 2026-01-31 +1 month forward to 2026-03-03.
        // Left unguarded that puts the month-1 milestone in March and stops
        // milestone_month tracking the calendar at all.
        $this->travelTo('2026-04-15');

        $employee = $this->makeEmployee(hiredOn: '2026-01-31');

        $this->artisan('shirts:generate-milestones')->assertSuccessful();

        $due = EmployeeShirtMilestone::query()
            ->where('employee_id', $employee->id)
            ->orderBy('milestone_month')
            ->pluck('due_date', 'milestone_month')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $this->assertSame([
            1 => '2026-02-28',
            2 => '2026-03-31',
        ], $due);
    }

    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->travelTo('2026-04-15');

        $employee = $this->makeEmployee(hiredOn: '2026-01-15');

        $this->artisan('shirts:generate-milestones')->assertSuccessful();
        $first = EmployeeShirtMilestone::query()->count();

        $this->artisan('shirts:generate-milestones')->assertSuccessful();

        $this->assertSame(3, $first, 'January 15 to April 15 is three completed months.');
        $this->assertSame($first, EmployeeShirtMilestone::query()->count());
    }

    /**
     * Manual entries carry a NULL milestone_month, and MySQL/SQLite both treat
     * NULLs as distinct in a unique index — which is exactly what lets a store
     * raise as many ad-hoc entries as it likes.
     */
    public function test_null_milestone_month_rows_can_coexist(): void
    {
        $employee = $this->makeEmployee(hiredOn: '2026-01-15');

        foreach (range(1, 3) as $ignored) {
            EmployeeShirtMilestone::query()->create([
                'employee_id' => $employee->id,
                'store_id' => $employee->latestStore->store_id,
                'milestone_month' => null,
                'stint_start_date' => null,
                'source' => 'manual',
                'status' => 'pending_entry',
            ]);
        }

        $this->assertSame(3, EmployeeShirtMilestone::query()->whereNull('milestone_month')->count());
    }

    public function test_terminated_employees_stop_earning_milestones(): void
    {
        $this->travelTo('2026-04-15');

        $employee = $this->makeEmployee(hiredOn: '2026-01-15');

        DB::table('employee_status_histories')->insert([
            'employee_id' => $employee->id,
            'status' => 'terminated',
            'effective_date' => '2026-02-01',
            'store_id' => $employee->latestStore->store_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('shirts:generate-milestones')->assertSuccessful();

        $this->assertSame(0, EmployeeShirtMilestone::query()->count());
    }

    /**
     * A rehire is a fresh stint, so the clock restarts from the rehire date
     * rather than continuing from the original hire.
     */
    public function test_rehired_employees_start_a_new_stint(): void
    {
        $this->travelTo('2026-06-15');

        $employee = $this->makeEmployee(hiredOn: '2025-01-15');

        DB::table('employee_status_histories')->insert([
            [
                'employee_id' => $employee->id,
                'status' => 'resigned',
                'effective_date' => '2025-06-01',
                'store_id' => $employee->latestStore->store_id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_id' => $employee->id,
                'status' => 'rehired',
                'effective_date' => '2026-04-10',
                'store_id' => $employee->latestStore->store_id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->artisan('shirts:generate-milestones')->assertSuccessful();

        $milestones = EmployeeShirtMilestone::query()->orderBy('milestone_month')->get();

        $this->assertCount(2, $milestones, 'April 10 to June 15 is two completed months.');
        $this->assertSame('2026-04-10', $milestones->first()->stint_start_date->toDateString());
        $this->assertSame('2026-05-10', $milestones->first()->due_date->toDateString());
    }

    public function test_since_bounds_the_backfill(): void
    {
        $this->travelTo('2026-04-15');

        $this->makeEmployee(hiredOn: '2026-01-15');

        $this->artisan('shirts:generate-milestones', ['--since' => '2026-03-01'])->assertSuccessful();

        // Only the month-2 (Mar 15) and month-3 (Apr 15) milestones qualify.
        $this->assertSame(
            [2, 3],
            EmployeeShirtMilestone::query()->orderBy('milestone_month')->pluck('milestone_month')->all()
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->travelTo('2026-04-15');

        $this->makeEmployee(hiredOn: '2026-01-15');

        $this->artisan('shirts:generate-milestones', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, EmployeeShirtMilestone::query()->count());
    }

    public function test_employees_with_no_store_are_skipped_loudly(): void
    {
        $this->travelTo('2026-04-15');

        $employee = $this->makeEmployee(hiredOn: '2026-01-15');
        DB::table('employee_stores')->where('employee_id', $employee->id)->delete();

        $this->artisan('shirts:generate-milestones')
            ->expectsOutputToContain("Employee {$employee->id} has no store assignment")
            ->assertSuccessful();

        $this->assertSame(0, EmployeeShirtMilestone::query()->count());
    }

    /**
     * The entry form writes the size onto the employee's profile, including
     * for employees who have no obsession row yet — the case that needed
     * birth_date to become nullable.
     */
    public function test_entry_saves_the_shirt_size_onto_the_employee_profile(): void
    {
        $employee = $this->makeEmployee(hiredOn: '2026-01-15');
        $store = Store::query()->firstOrFail();

        $this->assertNull($employee->obsession, 'precondition: no obsession row yet');

        $milestone = EmployeeShirtMilestone::query()->create([
            'employee_id' => $employee->id,
            'store_id' => $store->id,
            'milestone_month' => 1,
            'stint_start_date' => '2026-01-15',
            'due_date' => '2026-02-15',
            'source' => 'automatic',
            'status' => 'pending_entry',
        ]);

        $color = ShirtColor::query()->create(['name' => 'Pizza Red', 'hex_code' => '#C8102E']);
        $logo = ShirtLogo::query()->create([
            'name' => 'Primary',
            'file_path' => 'shirt-logos/x.svg',
            'mime_type' => 'image/svg+xml',
        ]);

        app(ShirtMilestoneWorkflowService::class)->submitEntry($store, $milestone, [
            'shirt_color_id' => $color->id,
            'shirt_logo_id' => $logo->id,
            't_shirt_size' => 'XL',
        ]);

        $this->assertSame('XL', $employee->fresh()->obsession?->t_shirt?->value);
        $this->assertSame('submitted', $milestone->fresh()->status->value);
        $this->assertSame('XL', $milestone->fresh()->t_shirt_size->value);
    }

    // -------------------------------------------------------------------------

    private function makeEmployee(string $hiredOn): Employee
    {
        $store = Store::query()->firstOrCreate(
            ['id' => 1],
            ['store_number' => '03759-00001']
        );

        $employee = Employee::query()->create([
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'gender' => 'male',
            'ssn' => '000-00-0000',
            'employment_type' => 'W2',
        ]);

        DB::table('employee_stores')->insert([
            'employee_id' => $employee->id,
            'store_id' => $store->id,
            'effective_date' => $hiredOn,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('employee_status_histories')->insert([
            'employee_id' => $employee->id,
            'status' => 'hired',
            'effective_date' => $hiredOn,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employee->refresh();
    }
}

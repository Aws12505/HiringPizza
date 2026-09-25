<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The shirt milestone entry writes a t-shirt size back onto the employee's
     * obsession row. Employees without one need that row created, which the
     * NOT NULL birth_date made impossible (SQLSTATE[HY000] 1364).
     *
     * Every reader of birth_date was audited and already null-guards:
     * EmployeeExportService, ManagerDashboardService::resolveBirthday,
     * EmployeeSeparationDateFixService, ExportHiringBernardTempService,
     * EmployeeCsvImportService and EmployeeQueryService.
     */
    public function up(): void
    {
        Schema::table('employee_obsessions', function (Blueprint $table) {
            $table->date('birth_date')->nullable()->change();
        });

        // Employee::obsession() is a HasOne but nothing enforced it, so
        // syncObsession()'s updateOrCreate could race into duplicates and make
        // "does this employee already have a size?" nondeterministic.
        // Skipped rather than failed when duplicates already exist — dedupe
        // first, then re-run this migration's index half by hand.
        if ($this->hasDuplicateEmployeeRows()) {
            return;
        }

        Schema::table('employee_obsessions', function (Blueprint $table) {
            $table->unique('employee_id', 'employee_obsessions_employee_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employee_obsessions', function (Blueprint $table) {
            if ($this->indexExists()) {
                $table->dropUnique('employee_obsessions_employee_id_unique');
            }
        });

        // Not reverted: rows created after this migration may hold a null
        // birth_date, and restoring NOT NULL would fail on them.
    }

    private function hasDuplicateEmployeeRows(): bool
    {
        return DB::table('employee_obsessions')
            ->select('employee_id')
            ->groupBy('employee_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes('employee_obsessions'))
            ->contains(fn (array $index) => $index['name'] === 'employee_obsessions_employee_id_unique');
    }
};

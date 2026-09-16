<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_shirt_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();

            // Which month of the current stint this shirt is for. NULL for
            // manually created milestones, which is what lets a store fill in
            // as many ad-hoc entries as it likes: MySQL treats NULLs as
            // distinct in a unique index.
            $table->unsignedSmallInteger('milestone_month')->nullable();
            // Anchor: the effective_date of the latest hired/rehired status.
            $table->date('stint_start_date')->nullable();
            // Invariant: stint_start_date->addMonthsNoOverflow(milestone_month).
            $table->date('due_date')->nullable();

            // Plain strings rather than native enum() columns: adding a status
            // stays a code-only deploy instead of a locking ALTER on what will
            // be the largest table here (rows ~= employees x months).
            $table->string('source', 32)->default('automatic');
            $table->string('status', 32)->default('pending_entry');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // --- Entry, filled by the store manager -------------------------
            $table->foreignId('shirt_color_id')->nullable()->constrained('shirt_colors')->nullOnDelete();
            $table->foreignId('shirt_logo_id')->nullable()->constrained('shirt_logos')->nullOnDelete();
            $table->foreignId('shirt_template_id')->nullable()->constrained('shirt_templates')->nullOnDelete();
            // Snapshots: the catalogue and the employee profile both change
            // over time, so what was actually ordered is recorded here.
            $table->string('t_shirt_size', 8)->nullable();
            $table->string('gender', 16)->nullable();
            $table->text('entry_notes')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();

            // --- Ordering ---------------------------------------------------
            $table->foreignId('ordered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('ordered_at')->nullable();
            $table->date('delivery_date')->nullable();

            // --- Delivery ---------------------------------------------------
            $table->foreignId('delivered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('delivered_at')->nullable();
            $table->text('delivery_notes')->nullable();

            // --- Cancellation -----------------------------------------------
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            // The nightly generator's idempotency key. Note the consequence:
            // a cancelled milestone leaves its row behind, so the generator
            // will never re-create it. That is intended.
            $table->unique(
                ['employee_id', 'stint_start_date', 'milestone_month'],
                'esm_emp_stint_month_unique'
            );

            $table->index(['status', 'due_date'], 'esm_status_due_date_index');
            $table->index(['store_id', 'status'], 'esm_store_status_index');
            $table->index(['employee_id', 'created_at'], 'esm_employee_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shirt_milestones');
    }
};

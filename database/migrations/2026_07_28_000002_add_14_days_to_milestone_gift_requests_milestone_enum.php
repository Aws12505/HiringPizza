<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * MODIFY ... ENUM is MySQL-only syntax. SQLite keeps the column as a plain
     * varchar with no enum constraint to narrow, so there is nothing to do
     * there — and without this guard the whole migration run dies on the
     * sqlite test database.
     *
     * The milestone_gift_* tables are dropped by a later migration; this only
     * has to survive being replayed in order.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestone_gift_requests MODIFY milestone ENUM('8_days','14_days','1_month','2_months','3_months','4_months','5_months','6_months','8_months','1_year','other') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE milestone_gift_requests MODIFY milestone ENUM('8_days','1_month','2_months','3_months','4_months','5_months','6_months','8_months','1_year','other') NOT NULL");
        }
    }
};

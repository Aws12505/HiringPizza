<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * The milestone gift workflow (create -> rating -> gift decision ->
     * final status) is replaced by the employee shirt milestone feature.
     *
     * The original create migrations are left in place on purpose: deleting
     * them would leave orphan tables plus stale `migrations` rows anywhere
     * they already ran, while migrate:fresh on a dev box would build a
     * different schema. This drops them forward instead.
     */
    private const TABLES = [
        // Ordered child -> parent so the foreign keys come apart cleanly.
        'milestone_gift_rating_answer_options',
        'milestone_gift_rating_answers',
        'milestone_gift_ratings',
        'milestone_gift_decisions',
        'milestone_gift_final_statuses',
        'milestone_gift_requests',
        'milestone_gift_question_options',
        'milestone_gift_questions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        // Irreversible: the create migrations still exist but re-running them
        // would not restore the dropped rows. Restore from a backup instead.
    }
};

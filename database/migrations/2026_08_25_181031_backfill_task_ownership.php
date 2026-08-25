<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Task ownership became visible-only-to-assignee for assistants. Every task
     * created before the assignee picker existed has assigned_to NULL — including
     * personal cards assistants made for themselves through the old composer — and
     * would silently vanish from their boards. De-facto personal cards (creator is
     * a non-admin) are re-pointed at their creator; genuinely unclaimed admin
     * queue items stay unassigned.
     *
     * One-time data backfill; idempotent because it only touches NULL rows, which
     * the new creation path never produces for assistants.
     */
    public function up(): void
    {
        DB::statement(
            'UPDATE tasks
             SET assigned_to = created_by
             WHERE assigned_to IS NULL
               AND created_by IS NOT NULL
               AND created_by IN (SELECT id FROM users WHERE role <> \'admin\')'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // A backfill cannot be undone without losing the original NULLs; the rows
        // it touched are indistinguishable from cards legitimately re-assigned to
        // their creator afterwards. Nothing to do.
    }
};

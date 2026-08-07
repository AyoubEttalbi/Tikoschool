<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove indexes that duplicate an existing one, and add the one that is missing.
 *
 * Every index is a write cost: each INSERT, UPDATE and DELETE has to maintain all of
 * them. Duplicates buy nothing — the query planner can only use one — so they are pure
 * overhead on exactly the tables that are written most.
 *
 * 1. membership_monthly_stats carried SIX indexes serving TWO lookup patterns, on the one
 *    table the dashboard writes on every single load:
 *        unique_school_month        (school_id, year, month)   <- keep, it is UNIQUE
 *        idx_school_month           (school_id, year, month)   <- duplicate
 *        idx_school_year_month      (school_id, year, month)   <- duplicate
 *        idx_current_month_lookup   (school_id, year, month)   <- duplicate
 *        idx_year_month             (year, month)              <- keep
 *        idx_year_month_performance (year, month)              <- duplicate
 *
 * 2. attendances — the write-heaviest table in the schema — carried TWO UNIQUE keys over
 *    the same five columns in a different order:
 *        attendances_student_class_date_teacher_subject_unique
 *            (student_id, classId, date, teacher_id, subject)
 *        attendances_unique_full
 *            (student_id, classId, teacher_id, subject, date)
 *    Column order only affects which read prefixes an index can serve; for uniqueness
 *    these are the identical constraint written twice, and every attendance insert paid to
 *    maintain and check both. The first is kept because it places `date` third, which
 *    pairs better with how attendances_date_index is already used.
 *
 * 3. teacher_membership_payments gains a standalone index on is_active. The monthly payout
 *    cron filters `active()` (is_active alone) and the only relevant existing index is
 *    (teacher_id, is_active) — unusable from its non-leftmost column when teacher_id is
 *    not in the predicate, so the cron did a full table scan plus a per-row JSON
 *    evaluation over every active payout record.
 *
 * Each drop is guarded by an information_schema check so this is safe to run against a
 * database where some of them were never created (a fresh install, or `tikoschool_vide`).
 */
return new class extends Migration
{
    private const DUPLICATE_INDEXES = [
        'membership_monthly_stats' => [
            'idx_school_month',
            'idx_school_year_month',
            'idx_current_month_lookup',
            'idx_year_month_performance',
        ],
        'attendances' => [
            'attendances_unique_full',
        ],
    ];

    public function up(): void
    {
        foreach (self::DUPLICATE_INDEXES as $table => $indexes) {
            foreach ($indexes as $index) {
                if ($this->indexExists($table, $index)) {
                    DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index}`");
                }
            }
        }

        if (! $this->indexExists('teacher_membership_payments', 'tmp_is_active_index')) {
            DB::statement(
                'ALTER TABLE `teacher_membership_payments` ADD INDEX `tmp_is_active_index` (`is_active`)'
            );
        }
    }

    public function down(): void
    {
        // Deliberately NOT restoring the duplicates. They were redundant, so recreating
        // them would only reintroduce write overhead; the constraints and lookups they
        // served are all still covered by the indexes that remain.
        if ($this->indexExists('teacher_membership_payments', 'tmp_is_active_index')) {
            DB::statement('ALTER TABLE `teacher_membership_payments` DROP INDEX `tmp_is_active_index`');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};

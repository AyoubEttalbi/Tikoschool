<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the columns this app actually filters and sorts on.
 *
 * Deliberately conservative — every index slows writes, so this only covers predicates that
 * appear repeatedly in controller code:
 *
 *  - transactions(type, payment_date)  : the payments dashboards and payroll checks.
 *      ORDERING NOTE: this is only useful because the `YEAR()/MONTH()` predicates were first
 *      rewritten as date ranges (Transaction::scopeInMonth/scopeInYear). Wrapping a column in
 *      a function makes the predicate non-sargable and the index unusable.
 *  - invoices(billDate) / (deleted_at, billDate) : invoice listings and date-range reports.
 *  - students(schoolId, status)        : the hottest filter pair in the app.
 *  - memberships(is_active, payment_status) : membership status filters.
 *  - messages(recipient_id, is_read, sender_id) : the unread-count aggregate.
 *  - attendances(date)                 : date-range scans; the existing composites lead with
 *      student_id/classId, so a bare date filter cannot use them.
 *
 * NOT added here: indexes on small lookup tables, and `deleted_at` on its own (too low
 * cardinality to help on its own). InnoDB already indexes every foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addIndex('transactions', ['type', 'payment_date'], 'transactions_type_payment_date_index');
        $this->addIndex('transactions', ['is_recurring', 'next_payment_date'], 'transactions_recurring_next_index');

        $this->addIndex('invoices', ['billDate'], 'invoices_billdate_index');
        $this->addIndex('invoices', ['deleted_at', 'billDate'], 'invoices_deleted_billdate_index');

        $this->addIndex('students', ['schoolId', 'status'], 'students_school_status_index');
        $this->addIndex('students', ['deleted_at', 'status'], 'students_deleted_status_index');

        $this->addIndex('memberships', ['is_active', 'payment_status'], 'memberships_active_status_index');

        $this->addIndex('messages', ['recipient_id', 'is_read', 'sender_id'], 'messages_unread_index');

        $this->addIndex('attendances', ['date'], 'attendances_date_index');

        $this->addIndex('announcements', ['visibility', 'date_announcement'], 'announcements_visibility_date_index');
    }

    public function down(): void
    {
        $this->dropIndex('transactions', 'transactions_type_payment_date_index');
        $this->dropIndex('transactions', 'transactions_recurring_next_index');
        $this->dropIndex('invoices', 'invoices_billdate_index');
        $this->dropIndex('invoices', 'invoices_deleted_billdate_index');
        $this->dropIndex('students', 'students_school_status_index');
        $this->dropIndex('students', 'students_deleted_status_index');
        $this->dropIndex('memberships', 'memberships_active_status_index');
        $this->dropIndex('messages', 'messages_unread_index');
        $this->dropIndex('attendances', 'attendances_date_index');
        $this->dropIndex('announcements', 'announcements_visibility_date_index');
    }

    /** Idempotent: skips silently if the table or index is missing, or the index exists. */
    private function addIndex(string $table, array $columns, string $name): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $name)) {
            return;
        }

        foreach ($columns as $column) {
            if (! Schema::hasColumn($table, $column)) {
                return;
            }
        }

        Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (Schema::hasTable($table) && $this->indexExists($table, $name)) {
            Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }

    private function indexExists(string $table, string $name): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $name)
            ->exists();
    }
};

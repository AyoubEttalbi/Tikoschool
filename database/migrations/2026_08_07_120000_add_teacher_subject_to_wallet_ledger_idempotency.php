<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the multi-subject hole in the wallet ledger's idempotency key.
 *
 * THE BUG
 * -------
 * The key was (teacher_id, invoice_id, month, reason) — it did not include the subject.
 * One teacher holding TWO subjects on the same membership generates two legitimate,
 * different credits for the same invoice and month. The second was silently rejected by
 * the unique constraint and that teacher was underpaid, with no error surfaced anywhere:
 * TeacherWalletService::move() treats a UniqueConstraintViolationException as "already
 * recorded" and returns false, which is exactly right for a genuine duplicate and exactly
 * wrong for this.
 *
 * Every membership in production currently assigns each teacher a single subject, so this
 * cannot fire today. That is a property of the current data, not of the design — nothing
 * in the schema or the application prevents a two-subject assignment being created.
 *
 * WHY THE COLUMN IS NOT NULL WITH A '' DEFAULT
 * --------------------------------------------
 * This is the part that matters. MySQL treats NULLs as DISTINCT in a unique index, so a
 * nullable teacher_subject would make every existing row (all of which would be NULL)
 * exempt from the constraint — silently destroying the idempotency guarantee for all
 * historical entries while appearing to strengthen it.
 *
 * NOT NULL DEFAULT '' keeps existing rows deduplicating against each other exactly as
 * before (they all share ''), while new rows carry the real subject and dedupe per subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_wallet_entries', function (Blueprint $table) {
            // NOT NULL + default: see the note above. Do not make this nullable.
            $table->string('teacher_subject', 191)->default('')->after('month');
        });

        Schema::table('teacher_wallet_entries', function (Blueprint $table) {
            $table->dropUnique('teacher_wallet_entries_idempotency');
        });

        Schema::table('teacher_wallet_entries', function (Blueprint $table) {
            $table->unique(
                ['teacher_id', 'invoice_id', 'month', 'reason', 'teacher_subject'],
                'teacher_wallet_entries_idempotency'
            );
        });
    }

    public function down(): void
    {
        Schema::table('teacher_wallet_entries', function (Blueprint $table) {
            $table->dropUnique('teacher_wallet_entries_idempotency');
        });

        Schema::table('teacher_wallet_entries', function (Blueprint $table) {
            $table->unique(
                ['teacher_id', 'invoice_id', 'month', 'reason'],
                'teacher_wallet_entries_idempotency'
            );
            $table->dropColumn('teacher_subject');
        });
    }
};

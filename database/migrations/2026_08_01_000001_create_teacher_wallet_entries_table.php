<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger for teacher wallet movements.
 *
 * `teachers.wallet` is a single decimal mutated from ~10 different places plus an admin form
 * field. Before this table, NO query in the codebase could answer "what should this teacher's
 * balance be?" — so any drift (from the signed-diff reversal bug, the multi-month double-pay,
 * or a stale edit form overwriting a concurrent credit) was undetectable and unrecoverable.
 *
 * The wallet column stays as a fast cached projection. This table is the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_wallet_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();

            // Nullable: manual adjustments and payouts have no originating invoice.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedBigInteger('payment_record_id')->nullable();

            // 'YYYY-MM' the movement relates to, when it is month-scoped.
            $table->string('month', 7)->nullable();

            // Signed: positive credits the teacher, negative claws back.
            $table->decimal('amount', 10, 2);

            // Balance immediately after applying this entry, for cheap auditing.
            $table->decimal('balance_after', 10, 2)->nullable();

            $table->string('reason', 64);
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // THE point of the table: a repeat credit for the same
            // (teacher, invoice, month, reason) is rejected by the database rather than
            // silently doubling a teacher's pay.
            //
            // Keyed on invoice_id rather than payment_record_id because the first credit
            // happens before the payment record exists. MySQL treats NULLs as distinct, so
            // rows with a NULL invoice_id (manual payouts and adjustments, which legitimately
            // repeat) are deliberately exempt from this constraint.
            $table->unique(
                ['teacher_id', 'invoice_id', 'month', 'reason'],
                'teacher_wallet_entries_idempotency'
            );

            $table->index(['teacher_id', 'created_at']);
            $table->index(['invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_wallet_entries');
    }
};

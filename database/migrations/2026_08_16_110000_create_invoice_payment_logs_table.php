<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE PAYMENT EVENT LOG — why the cashier was wrong about partial payments.
 *
 * The daily cash register read `invoices.amountPaid` on rows created that day. The
 * column is CUMULATIVE: a pupil who pays 200 on the 1st and the remaining 100 on the
 * 11th has amountPaid = 300 from the 11th onwards — so the 1st's sheet silently grew
 * to 300 and the 11th's showed nothing at all. Money arrived on two days and every
 * day's total was wrong the moment the second payment landed.
 *
 * This table records EVENTS, not state: one row per payment movement, with the DELTA
 * (positive or negative) and the moment it happened. The cashier sums rows, not
 * columns, so a day's total can no longer be rewritten by a later payment.
 *
 * Invoices whose amountPaid changed before this table existed are backfilled with one
 * row for the full cumulative amount at the invoice's created_at. That reproduces the
 * numbers the screen showed until now (no unexplainable jumps in history); the split
 * of an old partial payment across its real days is unknowable and is not invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payment_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            // Denormalised from the invoice on purpose: the cashier scopes and renders
            // by student even when the invoice row is later re-pointed or deleted.
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // The DELTA of this event — positive when money arrived, negative when an
            // edit reduced amountPaid (a correction or refund). Never the balance.
            $table->decimal('amount', 10, 2);
            // The invoice's cumulative amountPaid AFTER the event, for audit: the log
            // answers "what came in today", this answers "what did they owe then".
            $table->decimal('amount_paid_after', 10, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable()->index();

            $table->timestamps();

            // The cashier's daily sum. Paid-at, not created-at: an event logged at
            // 23:59 for money that arrived at 23:58 belongs to that day either way, and
            // backfilled rows predate this table by months.
            $table->index(['student_id', 'paid_at']);
        });

        // Backfill: one event per already-paid invoice, at the moment the invoice was
        // created — the only date history still knows. Runs inline because it is one
        // INSERT...SELECT on a small table and belongs to the database, not to a
        // runbook step that can be forgotten when the app is pointed at a new schema.
        // Soft-deleted invoices are excluded: their money was withdrawn with them.
        DB::statement('
            INSERT INTO invoice_payment_logs
                (invoice_id, student_id, amount, amount_paid_after, paid_at, created_at, updated_at)
            SELECT id, student_id, amountPaid, amountPaid, created_at, created_at, created_at
            FROM invoices
            WHERE amountPaid > 0
              AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payment_logs');
    }
};

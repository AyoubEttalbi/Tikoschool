<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VOIDED payment events.
 *
 * Invoices are SOFT deleted, so the `invoice_id` cascade on invoice_payment_logs never
 * fires — deleting an invoice left its payment events live in the daily register, and a
 * cashier kept counting money from a document that no longer existed.
 *
 * Rather than deleting the events (the register would lose its audit trail), deletion
 * VOIDs them: the rows stay, `voided_at` records when, and the cashier sums only
 * non-voided events — so the affected day's total returns to what it would have been
 * without the deleted invoice, which is what "this entry was a mistake" means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payment_logs', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payment_logs', function (Blueprint $table) {
            $table->dropColumn('voided_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give expenses somewhere to record their category.
 *
 * The expense form has always shown a twelve-option "Catégorie de dépense" select, plus a
 * free-text box for "Autre". The value was posted, was not in the validator, was not in
 * Transaction::$fillable, and had no column — so it was dropped three separate times over
 * and never reached the database.
 *
 * The knock-on was quieter than a lost field. PaymentForm warns you when an expense in the
 * same category was already recorded this month; it compares `t.category` against the one
 * on screen, and `t.category` was undefined on every row ever saved, so the comparison was
 * always false and the warning could not fire. The screen showed a category picker, a
 * duplicate guard that referenced it, and stored neither.
 *
 * Nullable because every existing row predates the column, and because staff payments
 * (salary / payment / wallet) have no category by design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('category')->nullable()->after('type');

            // The duplicate-expense check reads (type, category, payment_date). Without
            // this it is a full scan of a table that only grows.
            $table->index(['type', 'category', 'payment_date'], 'transactions_type_category_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_type_category_date_index');
            $table->dropColumn('category');
        });
    }
};

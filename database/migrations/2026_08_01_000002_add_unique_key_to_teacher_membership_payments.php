<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce one payout record per (invoice, teacher, subject).
 *
 * The absence of this constraint is why `teachers:cleanup-duplicates` and the hand-written
 * payments_resync_fix.php script exist: duplicate records meant a teacher could be credited
 * more than once for the same invoice.
 *
 * This migration deliberately REFUSES to run while duplicates exist rather than merging them
 * itself — collapsing financial rows is a decision for a human, not a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('teacher_membership_payments')
            ->select('invoice_id', 'teacher_id', 'teacher_subject', DB::raw('COUNT(*) as copies'))
            ->whereNotNull('invoice_id')
            ->groupBy('invoice_id', 'teacher_id', 'teacher_subject')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $sample = $duplicates->take(5)->map(
                fn ($d) => "invoice={$d->invoice_id} teacher={$d->teacher_id} subject={$d->teacher_subject} copies={$d->copies}"
            )->implode('; ');

            throw new RuntimeException(
                "Cannot add the unique key: {$duplicates->count()} duplicate payout record group(s) exist. "
                . "Resolve them first (run `php artisan payouts:audit` to list them, then "
                . "`php artisan teachers:cleanup-duplicates`), then re-run this migration. "
                . "Sample: {$sample}"
            );
        }

        Schema::table('teacher_membership_payments', function (Blueprint $table) {
            // invoice_id is nullable; MySQL treats NULLs as distinct, so records not tied to
            // an invoice are exempt. That is intended — they are not payment-driven.
            $table->unique(
                ['invoice_id', 'teacher_id', 'teacher_subject'],
                'tmp_invoice_teacher_subject_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('teacher_membership_payments', function (Blueprint $table) {
            $table->dropUnique('tmp_invoice_teacher_subject_unique');
        });
    }
};

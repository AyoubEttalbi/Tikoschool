<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two failure modes that the first version of this table could not tell apart.
 *
 * 1. THE SYSTEM WAS DOWN.
 *
 *    The gateway was logged out, or nobody was running a worker, for an evening or for
 *    four days. Nothing was wrong with the message or the number — it was simply never
 *    attempted. Marking those `failed` is a lie, and worse, it puts them on the same
 *    retry budget as a genuinely broken number, so a long outage quietly burns every
 *    message's attempts and they are lost even after the gateway comes back.
 *
 *    `held` says "not attempted, waiting for the system". It consumes no attempts, and it
 *    is picked up whenever the gateway returns, however long that takes.
 *
 * 2. THE NUMBER IS DEAD.
 *
 *    A guardian changes phone, or the number was mistyped years ago in a way that still
 *    parses. Every notice to that student will fail, forever, and each one costs a pacing
 *    slot that a working number could have used. `students.guardianNumberFailures` counts
 *    consecutive failures so the app can stop after a few and put the student on a list
 *    for somebody to fix, instead of retrying into the void every day.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL cannot ALTER an enum through the Blueprint API, and doctrine/dbal is not
        // installed. Raw is the honest way; the column keeps its default.
        DB::statement(
            "ALTER TABLE outbound_messages
             MODIFY COLUMN status ENUM('pending','held','sent','failed','skipped','expired')
             NOT NULL DEFAULT 'pending'"
        );

        Schema::table('outbound_messages', function (Blueprint $table) {
            // Why it is waiting, in a form the screen can explain to a human.
            $table->string('hold_reason', 40)->nullable()->after('skip_reason');

            // When it STARTED waiting — not when it was created. "Queued 4 days ago" is
            // the number an administrator needs to decide whether it is still worth
            // sending, and created_at cannot answer it once a message has been held,
            // released and held again.
            $table->timestamp('held_since')->nullable()->after('scheduled_at');

            // The recovery sweep looks for held and old-pending rows by age.
            $table->index(['status', 'created_at'], 'outbound_messages_status_created_index');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->unsignedTinyInteger('guardianNumberFailures')
                ->default(0)
                ->after('notifyGuardian');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('guardianNumberFailures');
        });

        Schema::table('outbound_messages', function (Blueprint $table) {
            $table->dropIndex('outbound_messages_status_created_index');
            $table->dropColumn(['hold_reason', 'held_since']);
        });

        // Anything sitting in a status the old enum does not know would be truncated to
        // an empty string on the way down, so it is resolved first.
        DB::table('outbound_messages')->whereIn('status', ['held', 'expired'])
            ->update(['status' => 'failed']);

        DB::statement(
            "ALTER TABLE outbound_messages
             MODIFY COLUMN status ENUM('pending','sent','failed','skipped')
             NOT NULL DEFAULT 'pending'"
        );
    }
};

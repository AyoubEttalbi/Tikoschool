<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * THE APPROVAL GATE.
 *
 * Saving the register used to queue a WhatsApp message to every absent student's
 * guardian the moment the sheet was saved. One wrong checkbox on a teacher's screen
 * and a parent was told, from the school's own number, that their child was absent
 * when they were not.
 *
 * `awaiting_approval` sits between creation and `pending`: the notice exists —
 * rendered, snapshotted, deduplicated against re-saves — but nothing delivers it.
 * It moves to `pending` only when somebody with the right role deliberately sends it,
 * per row from the absence log or in one motion for the whole day.
 *
 * Placed next to `held` in the enum because both mean "deliberately not sending yet",
 * but they are opposites in one way that matters: `held` waits for the SYSTEM and is
 * released automatically when the gateway returns; `awaiting_approval` waits for a
 * HUMAN and is never released by the recovery sweep. It expires with age like
 * everything else, so an unnoticed notice cannot wait forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL cannot ALTER an enum through the Blueprint API, and doctrine/dbal is
        // not installed. Raw is the honest way; the column keeps its default.
        DB::statement(
            "ALTER TABLE outbound_messages
             MODIFY COLUMN status ENUM('pending','held','awaiting_approval','sent','failed','skipped','expired')
             NOT NULL DEFAULT 'pending'"
        );
    }

    public function down(): void
    {
        // Anything still waiting for a human cannot be sent by the old code, which has
        // no way to release it — recorded as skipped rather than silently dropped.
        DB::table('outbound_messages')
            ->where('status', 'awaiting_approval')
            ->update([
                'status' => 'skipped',
                'skip_reason' => 'cancelled',
                'last_error' => 'annulée : validation non effectuée avant la mise à jour',
            ]);

        DB::statement(
            "ALTER TABLE outbound_messages
             MODIFY COLUMN status ENUM('pending','held','sent','failed','skipped','expired')
             NOT NULL DEFAULT 'pending'"
        );
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every message this app sends to a parent, and every one it decided not to send.
 *
 * Until now a notification existed only as a queued job and a line in laravel.log. Once
 * the job finished nothing remained, so the school could not answer the only question it
 * ever actually asks: "was this parent told?"
 *
 * NOT called `notifications`. App\Models\User uses Illuminate\Notifications\Notifiable,
 * which reserves that exact table name for Laravel's own database channel (UUID primary
 * key, different columns). Taking the name would permanently block that channel and
 * mislead every future reader into thinking this is Laravel's table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();

            // For joins and reporting only. Deliberately NOT part of any unique index —
            // see idempotency_key below.
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();

            /*
             * ONE column, NOT NULL, plain UNIQUE — built in PHP.
             *
             * The obvious design is UNIQUE(attendance_id, recipient, channel). It does not
             * work, for the reason already documented for teacher_wallet_entries in
             * CLAUDE.md: MySQL treats NULLs as DISTINCT, so rows with a null column are
             * exempt from the constraint. Both of those columns are legitimately null here
             * — a manual send has no attendance, and a student with no guardian number has
             * no recipient — which are exactly the rows most in need of deduplication.
             */
            $table->string('idempotency_key', 191)->unique();

            $table->string('type', 40);          // absence
            $table->string('channel', 20);       // whatsapp — in the idempotency key

            // Snapshots, not lookups. The student's number can change tomorrow and the
            // template can be reworded; neither may rewrite what was actually sent.
            $table->string('recipient', 20)->nullable();
            $table->text('message')->nullable();

            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');

            // Why nothing was sent. A silent skip is how you end up unable to explain why
            // a parent was never contacted.
            $table->string('skip_reason', 40)->nullable();

            $table->string('provider', 20)->nullable();

            // Nullable now, indexed now: this is the handle a delivery webhook will use to
            // find the row when Evolution's receipts get wired up. Cheap forward-compat.
            $table->string('provider_message_id', 191)->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();

            // Quiet hours: a class marked absent at 22:15 must not message parents at
            // midnight. Null means "as soon as possible".
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            // Serves the daily-cap count, which is a COUNT of today's sent rows.
            $table->index(['status', 'sent_at'], 'outbound_messages_status_sent_index');
            // Serves "what has this student's guardian been told", the student page.
            $table->index(['student_id', 'created_at'], 'outbound_messages_student_created_index');
            $table->index('provider_message_id', 'outbound_messages_provider_msg_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_messages');
    }
};

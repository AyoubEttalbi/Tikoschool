<?php

namespace App\Services;

use App\Jobs\SendOutboundMessage;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\OutboundMessage;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\WhatsApp;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * Turns a school event into a recorded, de-duplicated, scheduled message.
 *
 * Nothing above this class names WhatsApp. The attendance code says "a student was
 * absent"; which channel carries that, and whether it is carried at all, is decided here.
 *
 * Every decision leaves a row — including the decision NOT to send. A silent skip is how
 * a school ends up unable to answer why a parent was never told, and skips are common:
 * missing guardian number, unusable guardian number, notifications turned off.
 */
class OutboundMessageService
{
    /**
     * Create (or find) the absence notice for one attendance row.
     *
     * Returns the row in every case — sent, queued, or skipped — or null when this exact
     * notice already exists, which is the signal that nothing new was queued.
     *
     * $awaitApproval is the register path: the notice is recorded (rendered, snapshotted,
     * deduplicated) as `awaiting_approval` and NOTHING is dispatched. A teacher's wrong
     * checkbox must not reach a parent without a human in between; an admin or assistant
     * releases the day's notices from the absence log when they are satisfied they are
     * right. The manual "notifier le parent" button keeps the default — the person
     * pressing it IS the approval.
     */
    public function createForAbsence(Attendance $attendance, array $context = [], bool $awaitApproval = false): ?OutboundMessage
    {
        $student = $attendance->student ?: Student::find($attendance->student_id);

        if (! $student) {
            return null;
        }

        /*
         * Keyed on what the absence IS, not on the row that happens to record it.
         *
         * AttendanceController::store() dispatches from OUTSIDE the create/update branch,
         * so re-saving a class sheet to correct one typo re-sent a WhatsApp message to
         * every absent student's parent in that class. That is the bug this key exists to
         * kill.
         *
         * The obvious key is the attendance row's id — and it is wrong. Marking a student
         * PRESENT deletes their attendance row outright (see store()), so a present→absent
         * toggle inserts a brand new row with a brand new id, mints a brand new key, and
         * messages the guardian again. A teacher does not need a second school or any bug
         * to do that; toggling one checkbox back and forth is enough, and the only thing
         * limiting it would be the 8-second pacer.
         *
         * So the key is the attendance table's own unique tuple, which survives the row
         * being deleted and recreated: the same student, the same day, the same class,
         * teacher and subject is the same absence, however many times it is re-entered.
         */
        return $this->create(
            student: $student,
            attendance: $attendance,
            key: 'absence:'.implode(':', [
                $attendance->student_id,
                Carbon::parse($attendance->date)->toDateString(),
                $attendance->classId,
                $attendance->teacher_id ?? 'none',
                // Hashed, not interpolated: `subject` is free text and could otherwise
                // push the key past its 191-character column or smuggle a ':' separator
                // into it and collide with a different absence.
                substr(sha1((string) $attendance->subject), 0, 12),
            ]),
            context: $context + [
                'subject' => $attendance->subject,
                'date' => $attendance->date,
                'teacher_id' => $attendance->teacher_id,
                'class_id' => $attendance->classId,
            ],
            awaitApproval: $awaitApproval,
        );
    }

    /**
     * The manual "notifier le parent" button, which has no attendance row in scope.
     *
     * Scoped to one notice per student per day on purpose. The realistic duplicate here is
     * a staff member pressing the button again because the page gave them no feedback, and
     * a parent receiving the same absence notice four times is worse than not receiving it.
     */
    public function createManual(Student $student, array $context = []): ?OutboundMessage
    {
        return $this->create(
            student: $student,
            attendance: null,
            key: 'absence:manual:'.implode(':', [
                $student->id,
                now()->toDateString(),
                substr(sha1((string) ($context['subject'] ?? '')), 0, 12),
            ]),
            context: $context,
        );
    }

    private function create(Student $student, ?Attendance $attendance, string $key, array $context, bool $awaitApproval = false): ?OutboundMessage
    {
        $channel = OutboundMessage::CHANNEL_WHATSAPP;
        $key .= ':'.$channel;

        $base = [
            'school_id' => $student->schoolId,
            'student_id' => $student->id,
            'attendance_id' => $attendance?->id,
            'idempotency_key' => $key,
            'type' => OutboundMessage::TYPE_ABSENCE,
            'channel' => $channel,
        ];

        // Decide the outcome BEFORE inserting, so a skip is a row rather than a silence.
        [$recipient, $skipReason] = $this->resolveRecipient($student);

        if ($skipReason !== null) {
            return $this->insert($base + [
                'status' => OutboundMessage::STATUS_SKIPPED,
                'skip_reason' => $skipReason,
            ]);
        }

        $scheduledAt = $this->nextSendableMoment();

        $message = $this->insert($base + [
            'recipient' => $recipient,
            // Snapshot, not a reference. The template can be reworded tomorrow and the
            // student can be renamed; neither may change what this parent was told.
            'message' => $this->render($student, $context),
            // `scheduled_at` on an awaiting row is provisional — release() recomputes it
            // from the moment a human actually approves, quiet hours included.
            'status' => $awaitApproval
                ? OutboundMessage::STATUS_AWAITING_APPROVAL
                : OutboundMessage::STATUS_PENDING,
            'scheduled_at' => $scheduledAt,
        ]);

        if ($message === null) {
            /*
             * Someone already recorded this exact notice.
             *
             * For the register that is the whole answer: the re-save changes nothing — and
             * it must NOT release the existing row. A teacher correcting one typo on a
             * sheet is not approving anything; leaving the release here would make every
             * re-save the de-facto end-of-day validation.
             *
             * For the manual button it is different. The register's copy may be sitting in
             * `awaiting_approval` — nobody has been told anything — and the person who
             * just pressed "Notifier le parent" is explicitly asking for it to go out.
             * Telling them "déjà notifié" for a message that never left the building is
             * the one wrong answer, so the press becomes the approval.
             */
            if (! $awaitApproval) {
                $existing = OutboundMessage::where('idempotency_key', $key)->first();

                if ($existing !== null && $existing->status === OutboundMessage::STATUS_AWAITING_APPROVAL) {
                    $this->release($existing);

                    return $existing->refresh();
                }
            }

            return null;   // already sent, queued or refused by an earlier request
        }

        /*
         * afterCommit() is load-bearing, not decoration.
         *
         * AttendanceController::store() wraps its whole body in a transaction, and this
         * runs inside it. Today the job lands in the same MySQL connection as the app, so
         * the `jobs` INSERT is enrolled in that same transaction and is invisible to a
         * worker until COMMIT — which makes it work by coincidence of configuration, not
         * by design. Point QUEUE_CONNECTION at Redis, or give the queue its own database
         * connection, and the job becomes visible IMMEDIATELY: a worker picks it up before
         * the transaction commits, finds no outbound_messages row, and returns quietly
         * because "row missing" is indistinguishable from "already handled". The parent is
         * then never told, with no error and no retry.
         */
        if (! $awaitApproval) {
            $this->dispatchFor($message);
        }

        return $message;
    }

    /**
     * Queue the delivery of a row that is already `pending`.
     *
     * Public because the recovery sweep releases held messages back onto the queue and
     * must not duplicate the dispatch options — the queue name, afterCommit() and the
     * quiet-hours delay all matter and all belong in one place.
     */
    public function dispatchFor(OutboundMessage $message): void
    {
        SendOutboundMessage::dispatch($message->id)
            ->onQueue('whatsapp')
            ->afterCommit()
            ->delay($message->scheduled_at && $message->scheduled_at->isFuture()
                ? $message->scheduled_at
                : $this->nextSendableMoment());
    }

    /**
     * Approve one waiting notice and put it on the queue.
     *
     * The status change is a guarded UPDATE — `WHERE status = 'awaiting_approval'` — and
     * only the call that changes a row dispatches. Two people validating the same day at
     * the same moment, or a double-click on the button, must produce one job, not two:
     * unlike `held`, this state is left by humans acting in parallel, so the race is not
     * theoretical.
     *
     * The recipient is re-resolved rather than trusted from the snapshot: the guardian's
     * number may have been corrected (or the family opted out) between the register save
     * and the approval, and the approval means "send it now", not "send what we knew
     * hours ago". A refusal there is recorded as a skip so the screen can say why.
     */
    public function release(OutboundMessage $message): bool
    {
        if ($message->status !== OutboundMessage::STATUS_AWAITING_APPROVAL) {
            return false;
        }

        // The FK is cascadeOnDelete so this predates it — a legacy row with no student
        // can never be sent, and recording why beats returning a button that refuses.
        if (! $message->student) {
            $message->update([
                'status' => OutboundMessage::STATUS_SKIPPED,
                'skip_reason' => OutboundMessage::SKIP_STUDENT_ARCHIVED,
            ]);

            return false;
        }

        [$recipient, $skipReason] = $this->resolveRecipient($message->student);

        if ($skipReason !== null) {
            OutboundMessage::where('id', $message->id)
                ->where('status', OutboundMessage::STATUS_AWAITING_APPROVAL)
                ->update([
                    'status' => OutboundMessage::STATUS_SKIPPED,
                    'skip_reason' => $skipReason,
                ]);

            $message->refresh();

            return false;
        }

        $claimed = OutboundMessage::where('id', $message->id)
            ->where('status', OutboundMessage::STATUS_AWAITING_APPROVAL)
            ->update([
                'recipient' => $recipient,
                'status' => OutboundMessage::STATUS_PENDING,
                'skip_reason' => null,
                'scheduled_at' => $this->nextSendableMoment(),
            ]);

        if ($claimed === 0) {
            // Somebody released it between our read and our write. Their dispatch is the
            // one that counts; reporting a refusal here would send the reader hunting
            // for a problem that does not exist.
            $message->refresh();

            return false;
        }

        $this->dispatchFor($message->refresh());

        Log::info('Outbound message released after approval', ['id' => $message->id]);

        return true;
    }

    /**
     * The end-of-day motion: approve every waiting notice for one day's absences.
     *
     * Selected by the ABSENCE's date, not the row's created_at. A register saved today
     * can be backfilling yesterday's sheet, and the reviewer validating "yesterday" is
     * looking at yesterday's absences — the two dates only coincide when nothing was
     * recorded late, which is exactly when the distinction is invisible.
     *
     * @param  \DateTimeInterface|string  $date
     * @param  array<int, int>|null  $schoolIds  null means no restriction (an admin).
     * @return array{released: int, skipped: int, refused: int}
     */
    public function releaseAwaitingForDate($date, ?array $schoolIds = null): array
    {
        $released = 0;
        $skipped = 0;
        $refused = 0;

        OutboundMessage::query()
            ->awaitingApproval()
            ->whereHas('attendance', fn ($query) => $query->whereDate('date', $date))
            ->when($schoolIds !== null, fn ($query) => $query->whereIn('school_id', $schoolIds))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$released, &$skipped, &$refused): void {
                foreach ($rows as $row) {
                    if ($this->release($row)) {
                        $released++;
                    } elseif ($row->refresh()->status === OutboundMessage::STATUS_SKIPPED) {
                        $skipped++;
                    } else {
                        $refused++;
                    }
                }
            });

        return ['released' => $released, 'skipped' => $skipped, 'refused' => $refused];
    }

    /**
     * Withdraw the waiting notices for one absence — the "the teacher got this wrong"
     * half of review.
     *
     * Called when an attendance row is deleted or corrected away from 'absent'. The
     * notice is not deleted: "we decided not to tell this parent, and here is why" is a
     * row like any other decision, and deleting it would let the same absence re-mint
     * the same key and look like it had never been reviewed.
     */
    public function cancelForAttendance(int $attendanceId): int
    {
        return OutboundMessage::query()
            ->where('attendance_id', $attendanceId)
            ->awaitingApproval()
            ->update([
                'status' => OutboundMessage::STATUS_SKIPPED,
                'skip_reason' => OutboundMessage::SKIP_CANCELLED,
            ]);
    }

    /**
     * Insert, treating a duplicate key as "someone already did this".
     *
     * firstOrCreate() would race: two concurrent saves of the same sheet both see no row
     * and both insert. Letting the database decide is the only version that actually holds
     * under concurrency — the same reason teacher_wallet_entries relies on its unique key
     * rather than a pre-check.
     */
    private function insert(array $attributes): ?OutboundMessage
    {
        try {
            return OutboundMessage::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * One method, returning the single recipient this product has today.
     *
     * Named for a list even though it resolves one number: when a second guardian is
     * added, this is the only place that changes. The schema already allows several rows
     * per attendance because `recipient` is part of the idempotency key.
     */
    private function resolveRecipient(Student $student): array
    {
        // A student who has left must not generate new notices to their guardian. This
        // only became reachable once the relation was made withTrashed() so the row could
        // outlive the student — before that it crashed instead.
        if ($student->trashed()) {
            return [null, OutboundMessage::SKIP_STUDENT_ARCHIVED];
        }

        if (! $student->notifyGuardian) {
            return [null, OutboundMessage::SKIP_OPTED_OUT];
        }

        if (empty($student->guardianNumber)) {
            return [null, OutboundMessage::SKIP_NO_NUMBER];
        }

        $normalised = WhatsApp::normalise($student->guardianNumber);

        if ($normalised === null) {
            // Recorded rather than logged: an unreachable parent is a fact the school
            // needs on a screen, not a warning in a file nobody opens.
            return [null, OutboundMessage::SKIP_UNNORMALISABLE];
        }

        return [$normalised, null];
    }

    /**
     * Now, or the next moment the school is willing to message a parent.
     *
     * Without this a class marked absent at 22:15 lands on a parent's phone near midnight,
     * and an evening backlog quietly expires inside the job's retry window instead of
     * going out the next morning.
     */
    public function nextSendableMoment(): Carbon
    {
        $now = now();
        $from = $this->timeToday(config('school.notify_from', '09:00'));
        $until = $this->timeToday(config('school.notify_until', '21:30'));

        if ($now->lt($from)) {
            return $from;
        }

        if ($now->gt($until)) {
            return $from->addDay();
        }

        return $now;
    }

    private function timeToday(string $hhmm): Carbon
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return now()->setTime((int) $h, (int) $m, 0);
    }

    /** Render the Arabic notice, filling the gaps the caller could not supply. */
    private function render(Student $student, array $context): string
    {
        $date = $context['date'] ?? now();
        $date = $date instanceof Carbon ? $date : Carbon::parse($date);

        $teacher = ! empty($context['teacher_id']) ? Teacher::find($context['teacher_id']) : null;
        $class = ! empty($context['class_id']) ? Classes::find($context['class_id']) : null;
        $school = $student->school;

        return trim(view('notifications.whatsapp.absence', [
            // Every interpolated value is ISOLATED — see the Blade file. Latin names and
            // digits sitting inside Arabic reorder the punctuation around them otherwise,
            // which puts a full stop on the wrong side and can scramble a phone number.
            'studentName' => self::isolate(trim($student->firstName.' '.$student->lastName)),
            'subject' => self::isolate($context['subject'] ?? null) ?: self::isolate('غير محدد'),
            'date' => self::isolate($date->locale('ar')->isoFormat('dddd، D MMMM YYYY')),
            'teacherName' => self::isolate($teacher ? trim($teacher->first_name.' '.$teacher->last_name) : 'غير محدد'),
            'className' => self::isolate($class?->name) ?: self::isolate('غير محدد'),

            /*
             * BRAND from config, BRANCH from the row — and the split is the point.
             *
             * `schools` holds branches: "Tiko school C1", "Tiko school C2". That is what
             * the app needs to scope students and money, and it is not what a parent
             * knows the school as. Every message says "Tiko School" however many branches
             * exist, so the name comes from config and NOT from $school->name.
             *
             * The phone is the opposite: it genuinely differs per branch, and it is the
             * number the parent is being asked to call, so the branch's own value wins.
             */
            'schoolName' => self::isolate(config('school.name')),
            'schoolPhone' => self::isolate($school?->phone_number ?: config('school.phone')),
            // NOT run through plain(): it strips '_' because WhatsApp renders it as
            // italics, and a URL carrying utm_source came out as utmsource — a dead link.
            // This is operator-controlled config, not something a user typed.
            'schoolInstagram' => self::isolateTrusted((string) config('school.instagram')),
            'schoolHours' => config('school.hours'),

            // U+200F RIGHT-TO-LEFT MARK. Pins each Arabic line's base direction so a
            // leading emoji or digit cannot let the first Latin word decide it.
            'rtl' => "\u{200F}",

            // There is no gender field on students, so the message has always addressed
            // guardians in the masculine. Kept as-is rather than guessed from a name.
            'pronoun' => 'ابنكم',
            'verb' => 'تغيب',
        ])->render());
    }

    /**
     * Strip the characters WhatsApp treats as formatting before a value goes into a
     * message.
     *
     * Blade escapes HTML, which is beside the point: nothing here is rendered as HTML.
     * WhatsApp itself renders *bold*, _italic_, ~strike~ and ```code``` — so a subject or
     * a class name typed by staff can change how an official-looking school notice reads
     * on a parent's phone. Newlines go too, because they let free text impersonate the
     * message's own sections.
     */
    private static function plain(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = preg_replace('/[*_~`]+/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return trim($value);
    }

    /**
     * Seal a value into its own bidirectional run.
     *
     * U+2068 FIRST STRONG ISOLATE … U+2069 POP DIRECTIONAL ISOLATE. "First strong" means
     * the run's direction is decided by the value itself — Arabic stays RTL, a Latin name
     * or a URL goes LTR — and "isolate" means neutral characters at its edges (the full
     * stop after a school name, the colon before a phone number) stay with the ARABIC
     * around it instead of being dragged into the Latin run and rendered on the wrong side.
     *
     * Returns '' for an empty value so the Blade's @if checks still work; wrapping nothing
     * in control characters would produce a truthy two-character string and print an empty
     * line with a stray emoji.
     */
    private static function isolate(?string $value): string
    {
        return self::isolateTrusted(self::plain($value));
    }

    /**
     * Isolate a value that needs no sanitising — configuration, not user input.
     *
     * plain() strips '_' and '*' because WhatsApp renders them as formatting, which is
     * right for a name somebody typed into the app and wrong for a URL: it turned
     * `utm_source` into `utmsource` and produced a dead link in every message.
     */
    private static function isolateTrusted(string $value): string
    {
        return $value === '' ? '' : "\u{2068}".$value."\u{2069}";
    }

    /**
     * Put a failed or skipped row back in the queue.
     * One logical notice stays one row for its whole life — the same id, a bumped attempt
     * count. Minting a new key with a nonce would defeat the idempotency it exists for.
     */
    public function retry(OutboundMessage $message): bool
    {
        /*
         * An allow-list, not "anything except sent".
         *
         * Rejecting only SENT let a PENDING row be re-driven — and a pending row already
         * has a job waiting for it. Pressing "renvoyer" on one, or double-submitting the
         * form, dispatched a SECOND job for the same id; both would pass the
         * still-pending guard at the top of the job because neither had changed the
         * status yet, and the parent got the notice twice.
         */
        if (! in_array($message->status, [
            OutboundMessage::STATUS_FAILED,
            OutboundMessage::STATUS_SKIPPED,
            OutboundMessage::STATUS_HELD,
            OutboundMessage::STATUS_EXPIRED,
            OutboundMessage::STATUS_AWAITING_APPROVAL,
        ], true)) {
            return false;
        }

        /*
         * Awaiting is not a failure to recover — it is the approval gate, and the
         * guarded claim in release() is what keeps two parallel approvals from
         * dispatching twice. Everything the retry body does (re-resolve, schedule,
         * dispatch) is what release() does; only the claim differs.
         */
        if ($message->status === OutboundMessage::STATUS_AWAITING_APPROVAL) {
            return $this->release($message);
        }

        // The relation is withTrashed(), but a student can still be hard-deleted or the
        // row can predate one. resolveRecipient() takes a non-nullable Student, so
        // without this the screen 500s instead of saying why it cannot re-send.
        if (! $message->student) {
            return false;
        }

        [$recipient, $skipReason] = $this->resolveRecipient($message->student);

        if ($skipReason !== null) {
            $message->update(['status' => OutboundMessage::STATUS_SKIPPED, 'skip_reason' => $skipReason]);

            return false;
        }

        $scheduledAt = $this->nextSendableMoment();

        $message->update([
            'recipient' => $recipient,
            'status' => OutboundMessage::STATUS_PENDING,
            'skip_reason' => null,
            'hold_reason' => null,
            'held_since' => null,
            'failed_at' => null,
            'last_error' => null,
            'scheduled_at' => $scheduledAt,
        ]);

        $this->dispatchFor($message->refresh());

        Log::info('Outbound message re-queued', ['id' => $message->id]);

        return true;
    }
}

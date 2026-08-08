<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboundMessage extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** Never attempted: the gateway was down. Costs no attempts, resumes on its own. */
    public const STATUS_HELD = 'held';

    /** Too old to be worth delivering. A terminal state, recorded rather than deleted. */
    public const STATUS_EXPIRED = 'expired';

    public const TYPE_ABSENCE = 'absence';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const SKIP_NO_NUMBER = 'no_number';

    public const SKIP_UNNORMALISABLE = 'unnormalisable';

    public const SKIP_OPTED_OUT = 'opted_out';

    public const SKIP_STUDENT_ARCHIVED = 'student_archived';

    /** The number failed too many times in a row. Somebody has to fix it. */
    public const SKIP_UNREACHABLE_NUMBER = 'recipient_unreachable';

    protected $fillable = [
        'school_id', 'student_id', 'attendance_id', 'idempotency_key',
        'type', 'channel', 'recipient', 'message', 'status', 'skip_reason',
        'provider', 'provider_message_id', 'attempts', 'last_error',
        'hold_reason', 'scheduled_at', 'held_since', 'sent_at', 'failed_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'scheduled_at' => 'datetime',
        'held_since' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * This is now the second-highest-PII table in the app: `recipient` is a guardian's
     * mobile number and `message` contains a child's full name and their absence.
     * CLAUDE.md notes that only User declares $hidden and that every other model
     * serialises every column into the Inertia page props. Not repeating that here — the
     * admin screen needs the status, not the parent's phone number.
     */
    protected $hidden = ['recipient', 'message'];

    /**
     * withTrashed() is REQUIRED: Student uses SoftDeletes, and this row outlives the
     * student on purpose — it is the record of what their guardian was told. Without it
     * the relation returns null the moment a student is archived, and the retry path,
     * which type-hints a non-nullable Student, dies with a TypeError instead of a
     * message. Exactly the trap already documented for Invoice::membership().
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id')->withTrashed();
    }

    public function attendance()
    {
        return $this->belongsTo(Attendance::class, 'attendance_id');
    }

    public function school()
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function scopeSentToday($query)
    {
        return $query->where('status', self::STATUS_SENT)
            ->where('sent_at', '>=', now()->startOfDay());
    }

    /** Rows the recovery sweep may act on. */
    public function scopeRecoverable($query)
    {
        return $query->whereIn('status', [self::STATUS_HELD, self::STATUS_FAILED]);
    }

    /** A human answer for the admin screen, never the provider's raw response. */
    public function reason(): ?string
    {
        return match ($this->skip_reason) {
            self::SKIP_NO_NUMBER => 'Aucun numéro de tuteur enregistré',
            self::SKIP_UNNORMALISABLE => 'Numéro de tuteur invalide',
            self::SKIP_OPTED_OUT => 'Notifications désactivées pour cet élève',
            self::SKIP_STUDENT_ARCHIVED => 'Élève archivé — aucune notification envoyée',
            self::SKIP_UNREACHABLE_NUMBER => 'Numéro injoignable après plusieurs essais — à corriger',

            // Not a skip: a state. `held` is the important one — it must read as "waiting
            // for the service", never as a failure, because nothing was attempted and
            // nothing was lost.
            default => match ($this->status) {
                self::STATUS_HELD => match ($this->hold_reason) {
                    'gateway_disconnected' => 'En attente : WhatsApp est déconnecté',
                    'gateway_unreachable' => 'En attente : la passerelle ne répond pas',
                    default => 'En attente du service',
                },
                self::STATUS_EXPIRED => 'Trop ancien pour être envoyé',
                self::STATUS_FAILED => 'Échec de l\'envoi après plusieurs tentatives',
                default => null,
            },
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One movement of a teacher's wallet. Append-only — never update or delete a row here;
 * post a compensating entry instead.
 *
 * @see \App\Services\TeacherWalletService
 */
class TeacherWalletEntry extends Model
{
    protected $fillable = [
        'teacher_id',
        'invoice_id',
        'payment_record_id',
        'month',
        // Part of the unique idempotency key. NOT NULL with a '' default — a NULL would be
        // treated as distinct by MySQL and exempt the row from deduplication.
        'teacher_subject',
        'amount',
        'balance_after',
        'reason',
        'note',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    /** Movement reasons. Part of the idempotency key, so keep them stable. */
    public const REASON_IMMEDIATE = 'invoice.immediate';

    public const REASON_RECONCILE = 'invoice.reconcile';

    public const REASON_MONTHLY = 'schedule.monthly';

    public const REASON_REVERSAL = 'invoice.reversal';

    public const REASON_PAYOUT = 'payout';

    public const REASON_ADJUSTMENT = 'manual.adjustment';

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per payment EVENT, never per invoice.
 *
 * `invoices.amountPaid` is cumulative state; this table is the movement. The daily
 * cash register sums rows here, so a partial payment completed days later lands on the
 * day it actually arrived, and a day's total can no longer be rewritten retroactively.
 *
 * Deltas are signed: an edit that reduces amountPaid logs a negative amount, because
 * cash that leaves has to leave the day it leaves, or the register stops adding up.
 */
class InvoicePaymentLog extends Model
{
    protected $fillable = [
        'invoice_id',
        'student_id',
        'amount',
        'amount_paid_after',
        'created_by',
        'paid_at',
        'voided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'amount_paid_after' => 'decimal:2',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Events the register may still count. Voided events stay in the table as the audit
     * of what was withdrawn and when — they just stop being money.
     */
    public function scopeNotVoided($query)
    {
        return $query->whereNull('voided_at');
    }

    /**
     * Withdraw every event of one invoice — what deleting the invoice means for the
     * register. Idempotent: voiding twice keeps the first timestamp, so re-running
     * against a half-deleted state cannot rewrite history.
     */
    public static function voidForInvoice(int $invoiceId): int
    {
        return static::query()
            ->where('invoice_id', $invoiceId)
            ->whereNull('voided_at')
            ->update(['voided_at' => now()]);
    }

    /**
     * Record the movement implied by an amountPaid change, if there was one.
     *
     * Rounding to cents before comparing: the money columns historically arrived as
     * floats, and 200.0 vs 200.0000001 is a repricing artefact, not a payment.
     */
    public static function recordDelta(Invoice $invoice, float|string|null $previousAmountPaid, ?int $userId = null): ?self
    {
        $delta = round((float) $invoice->amountPaid - (float) $previousAmountPaid, 2);

        if ($delta === 0.0) {
            return null;
        }

        return static::create([
            'invoice_id' => $invoice->id,
            'student_id' => $invoice->student_id,
            'amount' => $delta,
            'amount_paid_after' => $invoice->amountPaid,
            'created_by' => $userId,
            'paid_at' => now(),
        ]);
    }

    /**
     * One event per already-paid invoice at its created_at — the migration's backfill,
     * separated so it can be tested and re-run against a restored dump.
     *
     * Idempotent per invoice: an invoice that already has any log row has a history and
     * keeps it; only invoices with no rows at all get their opening event.
     *
     * @return int number of events created
     */
    public static function backfillExisting(): int
    {
        $alreadyLogged = static::distinct()->pluck('invoice_id')->all();

        // Soft-deleted invoices are excluded: their events were voided with them, and
        // re-running against a restored dump must not put withdrawn money back on the
        // register.
        $invoices = Invoice::whereNull('deleted_at')
            ->where('amountPaid', '>', 0)
            ->whereNotIn('id', $alreadyLogged)
            ->get(['id', 'student_id', 'amountPaid', 'created_at', 'created_by']);

        foreach ($invoices as $invoice) {
            static::create([
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'amount' => $invoice->amountPaid,
                'amount_paid_after' => $invoice->amountPaid,
                'created_by' => is_numeric($invoice->created_by) ? (int) $invoice->created_by : null,
                'paid_at' => $invoice->created_at,
            ]);
        }

        return $invoices->count();
    }
}

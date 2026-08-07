<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    /**
     * Every kind of money movement this table records.
     *
     * `salary`  — an assistant's monthly salary, capped at what they are still owed.
     * `payment` — a payout to a teacher, taken out of their wallet.
     * `wallet`  — money added TO a teacher's wallet (an adjustment, not a payout).
     * `expense` — school spending, tied to nobody.
     */
    public const TYPE_SALARY = 'salary';

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_WALLET = 'wallet';

    public const TYPE_EXPENSE = 'expense';

    /** The types that move an employee balance, and so must be reverted when undone. */
    public const BALANCE_TYPES = [self::TYPE_SALARY, self::TYPE_WALLET, self::TYPE_PAYMENT];

    /**
     * The ONLY accepted recurrence values.
     *
     * There used to be three disagreeing lists: the form offered
     * monthly/quarterly/termly/semester/biannually/annually, store() accepted
     * weekly/monthly/quarterly/yearly, update() accepted monthly/yearly/custom, and
     * updateNextPaymentDate() switched on daily/weekly/biweekly/monthly/quarterly/
     * semiannually/annually. Picking "Annuel" in the form failed store()'s validator;
     * picking "Trimestriel" saved fine and then failed on the first edit; and anything
     * that did save fell through to the date switch's default and was rescheduled one
     * month later regardless of what had been chosen.
     *
     * One list, referenced by the validator, the scheduler and the form.
     */
    public const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'semiannually', 'yearly'];

    protected $fillable = [
        'type',
        'category',
        'user_id',
        'user_name',
        'amount',
        'rest',
        'description',
        'payment_date',
        'is_recurring',
        'frequency',
        'next_payment_date',
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'next_payment_date' => 'datetime',
        'is_recurring' => 'boolean',
        'amount' => 'decimal:2',
        'rest' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Transactions in a given calendar month.
     *
     * Uses a half-open date RANGE rather than `WHERE YEAR(payment_date) = ? AND
     * MONTH(payment_date) = ?`. Wrapping a column in a function makes the predicate
     * non-sargable, so MySQL cannot use an index on payment_date and falls back to a full
     * table scan — which is why adding that index would have achieved nothing on its own.
     */
    public function scopeInMonth($query, int|string $year, int|string $month)
    {
        $start = \Carbon\Carbon::createFromDate((int) $year, (int) $month, 1)->startOfDay();

        return $query->where('payment_date', '>=', $start)
            ->where('payment_date', '<', $start->copy()->addMonth());
    }

    /** Transactions in a given calendar year. Sargable — see scopeInMonth(). */
    public function scopeInYear($query, int|string $year)
    {
        $start = \Carbon\Carbon::createFromDate((int) $year, 1, 1)->startOfDay();

        return $query->where('payment_date', '>=', $start)
            ->where('payment_date', '<', $start->copy()->addYear());
    }

    /**
     * The date this recurrence falls due after $from.
     *
     * The single mapping from a value in self::FREQUENCIES to a date. Every scheduler in
     * the controller used to carry its own `switch`, and they disagreed — see the note on
     * FREQUENCIES. An unrecognised value advances one month, which is the historical
     * behaviour and the only safe guess, but the validator should have refused it first.
     */
    public function nextDateAfter(\Carbon\Carbon $from): \Carbon\Carbon
    {
        return match ($this->frequency) {
            'weekly' => $from->copy()->addWeek(),
            'quarterly' => $from->copy()->addMonths(3),
            'semiannually' => $from->copy()->addMonths(6),
            'yearly' => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }

    public function recurringPayments()
    {
        return $this->hasMany(\App\Models\RecurringTransactionPayment::class, 'recurring_transaction_id');
    }
}

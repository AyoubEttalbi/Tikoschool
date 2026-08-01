<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'type',
        'user_id',
        'user_name',
        'amount',
        'rest',
        'description',
        'payment_date',
        'is_recurring',
        'frequency',
        'next_payment_date'
    ];

    protected $casts = [
        'payment_date' => 'datetime',
        'next_payment_date' => 'datetime',
        'is_recurring' => 'boolean',
        'amount' => 'decimal:2',
        'rest' => 'decimal:2'
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

    public function recurringPayments()
    {
        return $this->hasMany(\App\Models\RecurringTransactionPayment::class, 'recurring_transaction_id');
    }
}

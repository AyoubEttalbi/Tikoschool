<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'membership_id',
        'months',
        'billDate',
        'creationDate',
        'totalAmount',
        'amountPaid',
        'rest',
        'student_id',
        'offer_id',
        'endDate',
        'includePartialMonth',
        'partialMonthAmount',
        'last_payment_date',
        'selected_months',
        'created_by',
        'type',
        'assurance_amount',
    ];

    protected $casts = [
        'billDate' => 'date',
        'creationDate' => 'date',
        'endDate' => 'date',
        'includePartialMonth' => 'boolean',
        'last_payment_date' => 'datetime',
        'selected_months' => 'array',
        // These four are decimal(10,2) in the migration but arrived in PHP as floats,
        // so `$invoice->rest == 0` could be false for a fully-paid invoice and
        // comparisons against Transaction/TeacherMembershipPayment (which DO cast
        // decimal:2) mixed representations. Casting makes the whole money layer
        // consistent; the inconsistency was the actual hazard.
        'totalAmount' => 'decimal:2',
        'amountPaid' => 'decimal:2',
        'rest' => 'decimal:2',
        'partialMonthAmount' => 'decimal:2',
        'assurance_amount' => 'decimal:2',
    ];

    // Relationship with Membership.
    //
    // withTrashed() is REQUIRED: Membership uses SoftDeletes, and without this the relation
    // resolves to null once a membership is trashed. InvoiceController::destroy() guards its
    // entire teacher-payment reversal behind `if ($membership)`, so deleting such an invoice
    // silently skipped the reversal and still reported success.
    // TeacherMembershipPayment::membership() already does this — this relation was the outlier.
    public function membership()
    {
        return $this->belongsTo(Membership::class, 'membership_id')->withTrashed();
    }

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    // Relationship with Offer (if applicable)
    public function offer()
    {
        return $this->belongsTo(Offer::class, 'offer_id');
    }

    // Relationship with TeacherMembershipPayment
    public function teacherMembershipPayments()
    {
        return $this->hasMany(TeacherMembershipPayment::class, 'invoice_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id',
        'months',
        'billDate',
        'creationDate',
        'totalAmount',
        'amountPaid',
        'rest',
        
        'offer_id',
        'endDate',
        'includePartialMonth',
        'partialMonthAmount',
    ];

    protected $casts = [
        'billDate' => 'date',
        'creationDate' => 'date',
        'endDate' => 'date',
        'includePartialMonth' => 'boolean',
    ];

    // Relationship with Membership (assuming it exists)
    public function membership()
{
    return $this->belongsTo(Membership::class , 'membership_id');
}

    // Relationship with Offer (if applicable)
    public function offer()
    {
        return $this->belongsTo(Offer::class, 'offer_id');
    }
}

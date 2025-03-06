<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'firstName',
        'lastName',
        'dateOfBirth',
        'billingDate',
        'address',
        'guardianName',
        'CIN',
        'phoneNumber',
        'email',
        'massarCode',
        'levelId',
        'class',
        'status',
        'assurance',
        'profile_image',
    ];

   

    public function level()
    {
        return $this->belongsTo(Level::class, 'levelId'); // Updated foreign key
    }
}

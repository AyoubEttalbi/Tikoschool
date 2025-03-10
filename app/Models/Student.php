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
        'guardianNumber',
        'CIN',
        'phoneNumber',
        'email',
        'massarCode',
        'levelId',
        'classId', // Add classId
        'schoolId', // Add schoolId
        'status',
        'assurance',
        'profile_image',
    ];

    // Relationship to Level
    public function level()
    {
        return $this->belongsTo(Level::class, 'levelId'); // Updated foreign key
    }

    // Relationship to Class
    public function class()
    {
        return $this->belongsTo(Classes::class, 'classId'); // Updated foreign key
    }

    // Relationship to School
    public function school()
    {
        return $this->belongsTo(School::class, 'schoolId'); // Updated foreign key
    }
}
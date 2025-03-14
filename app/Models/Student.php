<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Classes;
use Illuminate\Database\Eloquent\SoftDeletes;
class Student extends Model
{
    use HasFactory, SoftDeletes;

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
        'classId', // Foreign key for class
        'schoolId',
        'status',
        'assurance',
        'profile_image',
    ];

    // Track the old class ID before update
    protected $oldClassId;

    protected static function boot()
    {
        parent::boot();

        // Capture the old class ID before update
        static::updating(function ($student) {
            $student->oldClassId = $student->getOriginal('classId');
        });

        // Update class student count when a student is created
        static::created(function ($student) {
            $class = Classes::find($student->classId);
            if ($class) {
                $class->update([
                    'number_of_students' => $class->students()->count(),
                ]);
            }
        });

        // Update class student count when a student is updated
        static::updated(function ($student) {
            // Update the old class's student count
            if ($student->oldClassId) {
                $oldClass = Classes::find($student->oldClassId);
                if ($oldClass) {
                    $oldClass->update([
                        'number_of_students' => $oldClass->students()->count(),
                    ]);
                }
            }

            // Update the new class's student count
            $newClass = Classes::find($student->classId);
            if ($newClass) {
                $newClass->update([
                    'number_of_students' => $newClass->students()->count(),
                ]);
            }
        });

        // Update class student count when a student is deleted
        static::deleted(function ($student) {
            $class = Classes::find($student->classId);
            if ($class) {
                $class->update([
                    'number_of_students' => $class->students()->count(),
                ]);
            }
        });
    }

    // Relationship to Level
    public function level()
    {
        return $this->belongsTo(Level::class, 'levelId');
    }

    // Relationship to Class
    public function class()
    {
        return $this->belongsTo(Classes::class, 'classId'); // Explicitly specify foreign key
    }

    // Relationship to School
    public function school()
    {
        return $this->belongsTo(School::class, 'schoolId');
    }
}
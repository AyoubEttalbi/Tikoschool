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
        'guardianName',
        'CIN',
        'phoneNumber',
        'email',
        'massarCode',
        'levelId',
        'classId',
        'schoolId',
        'status',
        'assurance',
        'assuranceAmount',
        'profile_image',
        'hasDisease',
        'diseaseName',
        'medication',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'hasDisease' => 'integer',
        'assurance' => 'integer',
        'levelId' => 'integer',
        'classId' => 'integer',
        'schoolId' => 'integer',
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

    // Relationship to Attendances
    public function attendances()
    {
        return $this->hasMany(Attendance::class, 'student_id');
    }

    // Relationship to Memberships
    public function memberships()
    {
        return $this->hasMany(Membership::class, 'student_id');
    }

    // Relationship to Invoices (through memberships)
    public function invoices()
    {
        return $this->hasManyThrough(Invoice::class, Membership::class, 'student_id', 'membership_id');
    }

    /**
     * Get the hasDisease attribute.
     * Ensures that the value is explicitly returned as an integer (0 or 1)
     *
     * @param  mixed  $value
     * @return int
     */
    public function getHasDiseaseAttribute($value)
    {
        // Cast to integer to ensure 0 or 1
        return (int) $value;
    }

    /**
     * Set the hasDisease attribute.
     * Ensures that the value is stored as an integer (0 or 1)
     *
     * @param  mixed  $value
     * @return void
     */
    public function setHasDiseaseAttribute($value)
    {
        // Convert various input values to integer (0 or 1)
        if (is_string($value)) {
            $value = ($value === 'true' || $value === '1') ? 1 : 0;
        } else {
            $value = $value ? 1 : 0;
        }
        
        $this->attributes['hasDisease'] = $value;
    }

    /**
     * Get the assurance attribute.
     * Ensures that the value is explicitly returned as an integer (0 or 1)
     *
     * @param  mixed  $value
     * @return int
     */
    public function getAssuranceAttribute($value)
    {
        // Cast to integer to ensure 0 or 1
        return (int) $value;
    }

    /**
     * Set the assurance attribute.
     * Ensures that the value is stored as an integer (0 or 1)
     *
     * @param  mixed  $value
     * @return void
     */
    public function setAssuranceAttribute($value)
    {
        // Convert various input values to integer (0 or 1)
        if (is_string($value)) {
            $value = ($value === 'true' || $value === '1') ? 1 : 0;
        } else {
            $value = $value ? 1 : 0;
        }
        
        $this->attributes['assurance'] = $value;
    }

    /**
     * Get the promotion records for this student.
     */
    public function promotions()
    {
        return $this->hasMany(StudentPromotion::class, 'student_id');
    }
    
    /**
     * Get the current year's promotion status.
     */
    public function getCurrentPromotion()
    {
        $currentYear = date('Y');
        return $this->promotions()->where('school_year', $currentYear)->first();
    }
    
    /**
     * Check if student is promoted for the current year.
     */
    public function isPromoted()
    {
        $promotion = $this->getCurrentPromotion();
        return $promotion ? $promotion->is_promoted : true; // Default to true if no record
    }
}
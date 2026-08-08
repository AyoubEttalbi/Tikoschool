<?php

namespace App\Models;

use App\Services\StudentMovementService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
        'notifyGuardian',
        'guardianNumberFailures',
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
        'notifyGuardian' => 'boolean',
        'guardianNumberFailures' => 'integer',
    ];

    /**
     * In-memory defaults must match the column defaults.
     *
     * Eloquent does not read a column's DEFAULT back after an INSERT, so a Student built
     * in this request has `notifyGuardian === null` even though the row in the database
     * says 1. Anything that reads it as a boolean then sees "false" and concludes the
     * guardian opted out of notifications — a student created through the UI would have
     * been silently un-notifiable for the rest of that request.
     */
    protected $attributes = [
        'notifyGuardian' => true,
        'guardianNumberFailures' => 0,
    ];

    // Track the old class ID before update
    protected $oldClassId;

    protected static function boot()
    {
        parent::boot();

        // Capture the old class ID before update
        static::updating(function ($student) {
            $student->oldClassId = $student->getOriginal('classId');

            /*
             * A corrected number deserves a clean slate.
             *
             * guardianNumberFailures is what stops the app retrying a dead number forever.
             * Without this reset, fixing the typo would not be enough — the student would
             * stay on the "à corriger" list and their guardian would never be messaged
             * again, which is a worse bug than the one the counter exists to solve.
             */
            if ($student->isDirty('guardianNumber')) {
                $student->guardianNumberFailures = 0;
            }
        });

        // Update class student count when a student is created
        static::created(function ($student) {
            $class = Classes::find($student->classId);
            if ($class) {
                $class->update([
                    'number_of_students' => $class->students()->count(),
                ]);
            }

            // Record student inscription movement
            if ($student->billingDate) {
                $movementService = new StudentMovementService;
                $movementService->recordInscription($student);
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

            // Record student abandonment if status changed to inactive
            $originalStatus = $student->getOriginal('status');
            if ($originalStatus !== 'inactive' && $student->status === 'inactive') {
                $movementService = new StudentMovementService;
                $movementService->recordAbandonment($student, 'Status changed to inactive', $originalStatus);
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

    // Relationship to Student Movements
    public function movements()
    {
        return $this->hasMany(StudentMovement::class);
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

    /**
     * Get movement statistics for this student
     */
    public function getMovementStats($monthYear = null)
    {
        $query = $this->movements();

        if ($monthYear) {
            $query->where('month_year', $monthYear);
        }

        return [
            'inscribed' => $query->clone()->where('movement_type', 'inscribed')->count(),
            'abandoned' => $query->clone()->where('movement_type', 'abandoned')->count(),
        ];
    }

    /**
     * Get the latest movement for this student
     */
    public function getLatestMovement()
    {
        return $this->movements()->latest('movement_date')->first();
    }
}

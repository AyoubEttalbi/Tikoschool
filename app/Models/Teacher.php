<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Teacher extends Model
{
    use HasFactory, SoftDeletes;

    // `wallet` is deliberately NOT fillable.
    //
    // It is a cached projection of the append-only teacher_wallet_entries ledger and may
    // only move through TeacherWalletService::credit()/debit(), which row-locks and writes
    // a ledger row. Leaving it here meant one `update($request->all())` anywhere would
    // silently desynchronise the wallet from the ledger. TeacherController already strips
    // it by hand in two places (see the explicit unset()s there) — this makes that
    // enforcement rather than discipline. ArchitectureTest asserts it stays out.
    protected $fillable = [
        'first_name',
        'last_name',
        'address',
        'phone_number',
        'email',
        'status',
        'profile_image',
    ];

    protected $casts = [
        // decimal(10,2) in the schema. Transaction and TeacherMembershipPayment already
        // cast their money columns; this one did not, so wallet comparisons mixed floats
        // and strings across the payout code.
        'wallet' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        // Update class teacher count when a teacher is created
        static::created(function ($teacher) {
            $teacher->classes->each(function ($class) {
                static::updateClassTeacherCount($class->id); // Use $class->id
            });
        });

        // Update class teacher count when a teacher is updated (e.g., attached/detached from classes)
        static::updated(function ($teacher) {
            $teacher->classes->each(function ($class) {
                static::updateClassTeacherCount($class->id); // Use $class->id
            });
        });

        // Update class teacher count when a teacher is deleted
        static::deleted(function ($teacher) {
            $teacher->classes->each(function ($class) {
                static::updateClassTeacherCount($class->id); // Use $class->id
            });
        });
    }

    /**
     * Update the number_of_teachers field for a specific class.
     *
     * @param  int  $classId
     */
    protected static function updateClassTeacherCount($classId)
    {
        $class = Classes::find($classId);
        if ($class) {
            $class->update([
                'number_of_teachers' => DB::table('classes_teacher')
                    ->where('classes_id', $classId)
                    ->count(),
            ]);
        }
    }

    // Relationships
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'subject_teacher')->withTimestamps();
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classes::class, 'classes_teacher', 'teacher_id', 'classes_id')
            ->withTimestamps();
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_teacher')
            ->select('schools.*') // Explicitly select all columns from schools table
            ->withTimestamps();
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'email', 'email');
    }
}

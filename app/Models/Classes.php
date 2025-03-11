<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Classes extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'level_id',
        'number_of_students',
        'number_of_teachers',
    ];

    // Relationship to Level
    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    // Relationship to Students (if applicable)
    public function students()
    {
        return $this->hasMany(Student::class, 'classId');
    }

    // Relationship to Teachers (if applicable)
    public function teachers()
    {
        return $this->hasMany(Teacher::class,'classes_teacher');
    }
}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
   
        'school_id',
        'first_name',
        'last_name',
        'address',
        'phone_number',
        'email',
        'status',
        'wallet',
        'profile_image',
        'status',
        'wallet',
        
    ];

    // Relationships
    public function subjects()
    {
        return $this->belongsToMany(Subject::class, 'subject_teacher')->withTimestamps();
    }

    public function classes()
    {
        return $this->belongsToMany(Classes::class, 'classes_teacher')->withTimestamps();
    }
    public function schools()
{
    return $this->belongsToMany(School::class)->withTimestamps();
}

   


  
}
?>
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
    ];

    protected $hidden = [
        // No need to hide password here - it's in User model
    ];

    protected $casts = [
        'status' => 'string',
        'wallet' => 'decimal:2',
        'created_at' => 'datetime:Y-m-d',
        'updated_at' => 'datetime:Y-m-d',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'teacher_subject');
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classes::class, 'teacher_class', 'teacher_id', 'class_id');
    }

    // Accessors
   


  
}
?>
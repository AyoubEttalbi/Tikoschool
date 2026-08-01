<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assistant extends Model
{
    // AssistantFactory existed but the trait did not, so Assistant::factory() threw
    // "Call to undefined method".
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'address',
        'profile_image',
        
        'salary',
        'status',
    ];

    // `$dates` was removed in Laravel 10 and is ignored. SoftDeletes already casts
    // deleted_at, so this line did nothing at all.
    protected $casts = [
        'salary' => 'decimal:2',
    ];

    // Relationship with schools (many-to-many)
    public function schools()
{
    return $this->belongsToMany(School::class, 'assistant_school')->withTimestamps();
}


public function user()
{
    return $this->belongsTo(User::class, 'email', 'email');
}

    public function transactions()
    {
        return $this->hasMany(AssistantTransaction::class);
    }
}
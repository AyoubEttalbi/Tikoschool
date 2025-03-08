<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class School extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'address',
        'phone_number',
        'email',
    ];

    /**
     * Get the teachers associated with the school.
     */
    public function teachers(): HasMany
    {
        return $this->hasMany(Teacher::class);
    }
}
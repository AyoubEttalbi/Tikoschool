<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'content',
        'date_announcement',
        'date_start',
        'date_end',
        'visibility',
    ];

    // `protected $dates` was removed in Laravel 10 and is silently ignored, so these were
    // never actually cast — they reached the frontend as raw MySQL datetime strings
    // ("2025-09-01 00:00:00"), which is why `date_start.split("T")[0]` in AnnouncementsPage
    // returned the whole string instead of a date. Casting emits ISO-8601, which that code
    // (and `new Date(...)`) parses correctly.
    protected $casts = [
        'date_announcement' => 'datetime',
        'date_start' => 'datetime',
        'date_end' => 'datetime',
    ];

    public function reads()
    {
        return $this->hasMany(\App\Models\AnnouncementRead::class);
    }
}
<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /*
     * SoftDeletes on logins (column existed unused since the first migration):
     * staff destroy used to HARD-delete the users row, which RESTRICT foreign
     * keys (messages.sender_id/recipient_id, attendances.recorded_by) turn into
     * a guaranteed rollback for anyone who ever sent a message or recorded a
     * sheet. Deleting a login now keeps history intact while auth and every
     * Eloquent lookup skip the trashed row; controllers rewrite the email
     * before deleting so the unique index frees the address for re-hire.
     */

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'profile_image',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Raw storage is a logical relative path ("admins/<hex>.webp") or a legacy
     * absolute URL; consumers always get a renderable URL back.
     * Internal code needing the stored value must use getRawOriginal('profile_image').
     */
    public function getProfileImageAttribute($value)
    {
        return \App\Support\ProfileImageUrl::resolve($value);
    }

    public function teacher()
    {
        return $this->hasOne(Teacher::class, 'email', 'email');
    }

    public function assistant()
    {
        return $this->hasOne(Assistant::class, 'email', 'email');
    }

    public function announcementReads()
    {
        return $this->hasMany(\App\Models\AnnouncementRead::class);
    }

    /**
     * Check if the user is an admin.
     *
     * @return bool
     */
    public function getIsAdminAttribute()
    {
        return $this->role === 'admin';
    }
}

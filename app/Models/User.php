<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
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
            'is_active' => 'boolean',
            'screen_capture_acknowledged_at' => 'datetime',
            'notification_preferences' => 'array',
        ];
    }

    /**
     * Keep existing bcrypt/Argon hashes intact so Laravel can verify legacy
     * credentials and its auth provider can rehash them after successful login.
     */
    public function setPasswordAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['password'] = null;
            return;
        }

        $info = password_get_info($value);
        $this->attributes['password'] = ($info['algoName'] ?? 'unknown') !== 'unknown'
            ? $value
            : \Illuminate\Support\Facades\Hash::make($value);
    }

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(AttendanceEmployee::class, 'user_id');
    }
}

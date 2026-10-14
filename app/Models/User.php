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
     * The account for a sign-in name: an e-mail address, or an employee ID
     * (employee code, e.g. EMP-001, any letter case) of an employee with a login.
     */
    public static function findForLogin(string $login): ?self
    {
        $login = trim($login);
        if ($login === '') return null;
        if (str_contains($login, '@')) return self::where('email', $login)->first();
        $employee = AttendanceEmployee::whereRaw('LOWER(employee_code) = ?', [mb_strtolower($login)])->whereNotNull('user_id')->first();

        return $employee ? self::find($employee->user_id) : null;
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

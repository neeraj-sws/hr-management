<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayRule extends Model
{
    protected $fillable = ['name', 'type', 'weekday', 'weeks', 'month', 'day', 'scope_type', 'scope_id', 'effective_from', 'effective_until', 'is_active', 'created_by'];

    protected $casts = [
        'weeks' => 'array',
        'weekday' => 'integer',
        'month' => 'integer',
        'day' => 'integer',
        'effective_from' => 'date',
        'effective_until' => 'date',
        'is_active' => 'boolean',
    ];

    public const TYPES = ['weekly' => 'Every week', 'nth_weekday' => 'Nth weekday of month', 'fixed_date' => 'Fixed date (every year)'];
    public const SCOPES = ['company' => 'Whole company', 'department' => 'Department', 'team' => 'Team', 'employee' => 'Specific employee'];

    /** Most-specific scope wins: employee (4) > team (3) > department (2) > company (1). */
    public const SCOPE_PRIORITY = ['employee' => 4, 'team' => 3, 'department' => 2, 'company' => 1];

    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function priority(): int
    {
        return self::SCOPE_PRIORITY[$this->scope_type] ?? 0;
    }

    /** Human description, e.g. "2nd & 4th Saturday" or "Every Sunday". */
    public function describe(): string
    {
        return match ($this->type) {
            'weekly' => 'Every '.(self::WEEKDAYS[$this->weekday] ?? '?'),
            'nth_weekday' => collect($this->weeks ?? [])->map(fn ($w) => $w === -1 ? 'Last' : self::ordinal((int) $w))->join(' & ').' '.(self::WEEKDAYS[$this->weekday] ?? '?'),
            'fixed_date' => sprintf('%02d-%02d every year', (int) $this->day, (int) $this->month),
            default => $this->name,
        };
    }

    private static function ordinal(int $n): string
    {
        $suffix = match (true) {
            $n % 100 >= 11 && $n % 100 <= 13 => 'th',
            $n % 10 === 1 => 'st',
            $n % 10 === 2 => 'nd',
            $n % 10 === 3 => 'rd',
            default => 'th',
        };

        return $n.$suffix;
    }
}

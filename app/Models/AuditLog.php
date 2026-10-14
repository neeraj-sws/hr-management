<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;
    protected $fillable = ['actor_id', 'action', 'subject_type', 'subject_id', 'metadata', 'ip_address', 'user_agent', 'created_at'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }

    /**
     * Audit categories, by action prefix. Checked in order, so the more
     * specific "employee.asset_" lands in Assets before the general
     * "employee." in Employee. Anything not listed is System Activity.
     */
    public const CATEGORIES = [
        'assets' => ['Assets', ['employee.asset_']],
        'attendance' => ['Attendance', ['attendance.', 'shift.', 'overtime.', 'screen_capture.']],
        'payroll' => ['Payroll', ['payroll.', 'salary.', 'penalty.', 'compliance.', 'settlement.', 'payment.']],
        'leave' => ['Leave', ['leave.', 'leave_type.', 'holiday.']],
        'tasks' => ['Tasks', ['task.', 'project.', 'work_status.']],
        'expenses' => ['Expenses', ['expense.']],
        'employee' => ['Employee', ['employee.', 'user.', 'team.', 'profile.', 'note.', 'performance.', 'training.', 'candidate.', 'interview.']],
        'system' => ['System Activity', []],
    ];

    public static function categoryOf(string $action): string
    {
        foreach (self::CATEGORIES as $key => [, $prefixes]) {
            foreach ($prefixes as $prefix) if (str_starts_with($action, $prefix)) return $key;
        }

        return 'system';
    }

    public function category(): string
    {
        return self::categoryOf((string) $this->action);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category()][0];
    }

    /** Filters to one category with the same precedence as categoryOf(). */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        if (! isset(self::CATEGORIES[$category])) return $query;
        $earlier = [];
        foreach (self::CATEGORIES as $key => [, $prefixes]) {
            if ($key === $category) break;
            $earlier = [...$earlier, ...$prefixes];
        }
        $own = self::CATEGORIES[$category][1];
        if ($category === 'system') $own = [];
        $all = collect(self::CATEGORIES)->flatMap(fn ($c) => $c[1])->all();

        if ($own) {
            $query->where(function (Builder $q) use ($own) {
                foreach ($own as $prefix) $q->orWhere('action', 'like', $prefix.'%');
            });
        }
        foreach ($category === 'system' ? $all : $earlier as $prefix) $query->where('action', 'not like', $prefix.'%');

        return $query;
    }
}

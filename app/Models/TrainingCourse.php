<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingCourse extends Model
{
    /** Units a course duration is counted in (e.g. 6 months). */
    public const DURATION_UNITS = ['days' => 'days', 'weeks' => 'weeks', 'months' => 'months', 'years' => 'years'];

    protected $fillable = ['title', 'description', 'category', 'provider', 'duration_hours', 'fee', 'one_time_discount', 'default_installments', 'duration_label', 'duration_value', 'duration_unit', 'material_url', 'is_mandatory', 'is_active', 'created_by'];
    protected $casts = ['is_mandatory' => 'boolean', 'is_active' => 'boolean', 'duration_hours' => 'decimal:2', 'fee' => 'decimal:2', 'one_time_discount' => 'decimal:2', 'duration_value' => 'integer', 'default_installments' => 'integer'];

    /** Trainees enrolled in this course (Course fees). */
    public function enrollments(): HasMany { return $this->hasMany(TrainingEnrollment::class, 'training_course_id'); }

    public function assignments(): HasMany { return $this->hasMany(TrainingAssignment::class); }

    /** The topics in teaching order (HTML/CSS, JavaScript, React…), each with the mentors who can teach it. */
    public function topics(): HasMany { return $this->hasMany(TrainingCourseTopic::class)->orderBy('sort')->orderBy('id'); }

    /** The fee when paid in installments (the full fee). */
    public function installmentFee(): ?float
    {
        return $this->fee !== null ? round((float) $this->fee, 2) : null;
    }

    /** The fee when the whole amount is paid at once: the fee less the one-time discount. */
    public function atOnceFee(): ?float
    {
        return $this->fee !== null ? round(max(0, (float) $this->fee - (float) $this->one_time_discount), 2) : null;
    }

    /** "6 months", from the number and unit (or the text typed earlier). */
    public function durationText(): ?string
    {
        if ($this->duration_value && $this->duration_unit) {
            return $this->duration_value.' '.($this->duration_value === 1 ? rtrim($this->duration_unit, 's') : $this->duration_unit);
        }

        return $this->duration_label ?: null;
    }

    /** The last day of a course that starts on $start (6 months from 1 Oct = 31 Mar), or null with no duration. */
    public function endDateFrom(string $start): ?string
    {
        if (! $this->duration_value || ! isset(self::DURATION_UNITS[$this->duration_unit])) return null;
        $date = \Illuminate\Support\Carbon::parse($start);
        $date = match ($this->duration_unit) {
            'days' => $date->addDays($this->duration_value),
            'weeks' => $date->addWeeks($this->duration_value),
            'months' => $date->addMonthsNoOverflow($this->duration_value),
            'years' => $date->addYearsNoOverflow($this->duration_value),
        };

        return $date->subDay()->toDateString();
    }
}

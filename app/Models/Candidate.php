<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Candidate extends Model
{
    public const STAGES = ['applied' => 'Applied', 'screening' => 'Screening', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Rejected'];
    public const SOURCES = ['referral' => 'Referral', 'job_portal' => 'Job portal', 'website' => 'Company website', 'walk_in' => 'Walk-in', 'agency' => 'Agency', 'other' => 'Other'];

    protected $fillable = ['job_opening_id', 'name', 'email', 'phone', 'source', 'stage', 'resume_path', 'resume_name', 'notes', 'hired_employee_id', 'created_by'];

    public function jobOpening(): BelongsTo { return $this->belongsTo(JobOpening::class); }
    public function interviews(): HasMany { return $this->hasMany(Interview::class); }
    public function hiredEmployee(): BelongsTo { return $this->belongsTo(AttendanceEmployee::class, 'hired_employee_id'); }

    public function averageRating(): ?float
    {
        $ratings = $this->interviews->whereNotNull('rating')->pluck('rating');

        return $ratings->isEmpty() ? null : round($ratings->avg(), 1);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An HR letter template (appointment, offer, NOC …) with {placeholders}; see HrLetterService. */
class LetterTemplate extends Model
{
    use Concerns\HasCompany;

    protected $fillable = ['company_id', 'name', 'category', 'body', 'is_active', 'created_by', 'updated_by'];
    protected $casts = ['is_active' => 'boolean'];

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function editor() { return $this->belongsTo(User::class, 'updated_by'); }
}

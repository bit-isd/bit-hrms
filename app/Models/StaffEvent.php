<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEvent extends Model
{
    protected $fillable = [
        'staff_id',
        'type',
        'title',
        'occurred_on',
        'start_date',
        'end_date',
        'resumed_on',
        'from_grade_level_id',
        'to_grade_level_id',
        'leave_type',
        'leave_days',
        'disciplinary_action',
        'reason',
        'staff_response',
        'action_taken',
        'current_state',
        'training_type',
        'provider',
        'qualification_obtained',
        'circular_reference',
        'remarks',
        'created_by',
    ];

    protected $casts = [
        'occurred_on' => 'date:Y-m-d',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'resumed_on' => 'date:Y-m-d',
    ];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public function fromGradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'from_grade_level_id');
    }

    public function toGradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class, 'to_grade_level_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

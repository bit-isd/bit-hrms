<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPerformanceRating extends Model
{
    protected $fillable = ['staff_id', 'rating_year', 'rating', 'comments', 'entered_by'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}

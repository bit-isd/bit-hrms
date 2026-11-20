<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffConfidentialDocument extends Model
{
    protected $fillable = ['staff_id', 'title', 'category', 'storage_path', 'file_name', 'file_size', 'mime_type', 'uploaded_by'];

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}

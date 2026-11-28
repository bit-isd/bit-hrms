<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmploymentStatus extends Model
{
    protected $fillable = ['name', 'code', 'is_active_nominal', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'is_active_nominal' => 'boolean'];
}
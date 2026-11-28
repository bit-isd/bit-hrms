<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LookupOption extends Model
{
    public const CATEGORIES = [
        'lga' => 'LGAs of Origin',
        'title' => 'Staff Titles',
        'leave_type' => 'Leave Types',
        'disciplinary_action' => 'Disciplinary Actions',
    ];

    protected $fillable = ['category', 'value', 'sort_order', 'is_active'];
    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    public static function activeValues(string $category): array
    {
        return static::query()->where('category', $category)->where('is_active', true)->ordered()->pluck('value')->all();
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('value');
    }
}

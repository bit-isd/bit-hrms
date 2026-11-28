<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class Staff extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'staff';
    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::addGlobalScope('department_access', function (Builder $query) {
            $user = auth()->user();
            if ($user?->role?->code === 'DEPT_ADMIN') {
                $query->where('staff.department_id', $user->department_id ?? 0);
            }
        });
        static::updating(function (Staff $staff) {
            if ($staff->retirementDateHasPassed($staff->getRawOriginal('expected_retirement_date'))) {
                throw ValidationException::withMessages(['staff' => 'Retired staff records are read-only and cannot be reactivated.']);
            }

            if (!(bool) $staff->getRawOriginal('is_active')) {
                $dirtyKeys = array_keys($staff->getDirty());
                $isReactivation = (bool) ($staff->getAttributes()['is_active'] ?? false)
                    && $staff->isDirty('is_active')
                    && !array_diff($dirtyKeys, ['is_active', 'updated_by']);

                if (!$isReactivation) {
                    throw ValidationException::withMessages(['staff' => 'Suspended staff records are read-only. Reactivate the staff member before making changes.']);
                }
            }
        });
        static::deleting(function () {
            throw ValidationException::withMessages(['staff' => 'Staff records cannot be deleted. Deactivate the staff member instead.']);
        });
    }

    protected $fillable = [
        'staff_number',
        'ogun_employee_number',
        'file_number',
        'ippis_number',
        'title',
        'surname',
        'first_name',
        'middle_name',
        'gender',
        'date_of_birth',
        'place_of_birth',
        'state_of_origin',
        'lga_of_origin',
        'qualification',
        'residential_address',
        'phone_number',
        'official_email',
        'personal_email',
        'photo_path',
        'department_id',
        'unit_id',
        'designation_id',
        'grade_level_id',
        'step',
        'salary_structure',
        'employment_status_id',
        'date_of_first_appointment',
        'date_of_present_appointment',
        'date_of_confirmation',
        'date_of_last_promotion',
        'date_of_last_deployment',
        'expected_retirement_date',
        'current_station',
        'supervisor_id',
        'remarks',
        'created_by',
        'updated_by'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'date_of_birth' => 'date:Y-m-d',
        'date_of_first_appointment' => 'date:Y-m-d',
        'date_of_present_appointment' => 'date:Y-m-d',
        'date_of_confirmation' => 'date:Y-m-d',
        'expected_retirement_date' => 'date:Y-m-d',
    ];

    protected $appends = ['record_status', 'retirement_reason', 'confirmation_due'];

    public function getIsActiveAttribute($value): bool
    {
        return $this->record_status === 'active' && (bool) $value;
    }

    public function getRecordStatusAttribute(): string
    {
        if ($this->retirementDateHasPassed($this->expected_retirement_date)) {
            return 'retired';
        }

        return (bool) ($this->attributes['is_active'] ?? true) ? 'active' : 'suspended';
    }

    public function getRetirementReasonAttribute(): ?string
    {
        if (!$this->date_of_birth || !$this->date_of_first_appointment) {
            return null;
        }

        return self::calculateRetirementReason(
            $this->date_of_birth->toDateString(),
            $this->date_of_first_appointment->toDateString()
        );
    }

    public function getConfirmationDueAttribute(): bool
    {
        return $this->record_status !== 'retired'
            && $this->date_of_first_appointment !== null
            && $this->date_of_first_appointment->lte(Carbon::today()->subYears(2))
            && $this->date_of_confirmation === null;
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->title} {$this->surname}, {$this->first_name} {$this->middle_name}");
    }

    public static function calculateRetirementDate(string $dob, string $dofa): Carbon
    {
        $byAge = Carbon::parse($dob)->addYears(60);
        $byService = Carbon::parse($dofa)->addYears(35);
        return $byAge->lt($byService) ? $byAge : $byService;
    }

    public static function calculateRetirementReason(string $dob, string $dofa): string
    {
        $byAge = Carbon::parse($dob)->addYears(60);
        $byService = Carbon::parse($dofa)->addYears(35);

        return $byAge->lte($byService) ? 'Age 60' : '35 years of service';
    }

    private function retirementDateHasPassed(mixed $date): bool
    {
        return $date !== null && Carbon::parse($date)->lte(Carbon::today());
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }
    public function status(): BelongsTo
    {
        return $this->belongsTo(EmploymentStatus::class, 'employment_status_id');
    }
    public function postings(): HasMany
    {
        return $this->hasMany(StaffPosting::class)->orderBy('effective_date', 'desc');
    }
    public function documents(): HasMany
    {
        return $this->hasMany(StaffDocument::class)->orderBy('created_at', 'desc');
    }
    public function events(): HasMany
    {
        return $this->hasMany(StaffEvent::class)->orderByDesc('occurred_on');
    }
    public function performanceRatings(): HasMany
    {
        return $this->hasMany(StaffPerformanceRating::class)->orderBy('rating_year');
    }
    public function confidentialDocuments(): HasMany
    {
        return $this->hasMany(StaffConfidentialDocument::class)->latest();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Designation;
use App\Models\EmploymentStatus;
use App\Models\GradeLevel;
use App\Models\LookupOption;
use App\Models\Unit;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConfigurationController extends Controller
{
    private const MODEL_SECTIONS = [
        'departments' => ['model' => Department::class, 'label' => 'Department', 'staff_column' => 'department_id'],
        'units' => ['model' => Unit::class, 'label' => 'Unit', 'staff_column' => 'unit_id'],
        'designations' => ['model' => Designation::class, 'label' => 'Designation', 'staff_column' => 'designation_id'],
        'grade_levels' => ['model' => GradeLevel::class, 'label' => 'Grade level', 'staff_column' => 'grade_level_id'],
        'employment_statuses' => ['model' => EmploymentStatus::class, 'label' => 'Posting / employment status', 'staff_column' => 'employment_status_id'],
    ];

    // Where each free-text option is stored, and the column width that limits the value.
    private const OPTION_USAGE = [
        'lga' => ['table' => 'staff', 'column' => 'lga_of_origin', 'max' => 100],
        'title' => ['table' => 'staff', 'column' => 'title', 'max' => 20],
        'leave_type' => ['table' => 'staff_events', 'column' => 'leave_type', 'max' => 80],
        'disciplinary_action' => ['table' => 'staff_events', 'column' => 'disciplinary_action', 'max' => 120],
    ];

    private const OPTION_LABELS = ['lga' => 'LGA', 'title' => 'Staff title', 'leave_type' => 'Leave type', 'disciplinary_action' => 'Disciplinary action'];

    // Status codes referenced directly by staff filters and dashboard statistics.
    private const RESERVED_STATUS_CODES = ['ACTIVE', 'POSTED', 'ON_LEAVE'];

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->payload()]);
    }

    public function store(Request $request, string $section): JsonResponse
    {
        $this->ensureSection($section);
        $data = $this->validated($request, $section);

        if ($this->isOptionSection($section)) {
            $record = LookupOption::create($data + [
                'category' => $section,
                'sort_order' => $data['sort_order'] ?? ((int) LookupOption::where('category', $section)->max('sort_order') + 1),
            ]);
        } else {
            $record = $this->modelClass($section)::create($data);
        }

        AuditLogService::log(
            'CONFIG_CREATED', 'SYSTEM_CONFIGURATION', $record::class, $record->id, null, $this->auditValues($section, $record),
            description: sprintf('%s "%s" was added to the system configuration by %s.', $this->label($section), $this->displayName($section, $record), $request->user()->name)
        );

        return response()->json(['success' => true, 'message' => "{$this->label($section)} added.", 'data' => $this->payload()], 201);
    }

    public function update(Request $request, string $section, int $id): JsonResponse
    {
        $this->ensureSection($section);
        $record = $this->findRecord($section, $id);
        $data = $this->validated($request, $section, $record);
        $usage = $this->usageCount($section, $record);

        $record->fill($data);
        $this->guardUpdate($section, $record, $usage);
        $changes = $record->getDirty();
        if (!$changes) {
            return response()->json(['success' => true, 'message' => 'No changes were made.', 'data' => $this->payload()]);
        }

        $previous = array_intersect_key($record->getOriginal(), $changes);
        $record->save();

        AuditLogService::log(
            'CONFIG_UPDATED', 'SYSTEM_CONFIGURATION', $record::class, $record->id, $previous, $changes,
            description: sprintf(
                '%s "%s" was updated by %s. %s',
                $this->label($section),
                $this->displayName($section, $record),
                $request->user()->name,
                $this->describeChanges($previous, $changes)
            )
        );

        return response()->json(['success' => true, 'message' => "{$this->label($section)} updated.", 'data' => $this->payload()]);
    }

    public function destroy(Request $request, string $section, int $id): JsonResponse
    {
        $this->ensureSection($section);
        $record = $this->findRecord($section, $id);
        $usage = $this->usageCount($section, $record);
        $name = $this->displayName($section, $record);

        if ($usage > 0 || $this->isReserved($section, $record)) {
            return response()->json([
                'success' => false,
                'message' => $this->isReserved($section, $record)
                    ? "\"{$name}\" is required by the system and cannot be removed."
                    : "\"{$name}\" is used by {$usage} record(s) and cannot be removed. Deactivate it instead to hide it from new entries.",
            ], 409);
        }

        $values = $this->auditValues($section, $record);
        try {
            DB::transaction(fn () => $record->delete());
        } catch (QueryException) {
            return response()->json([
                'success' => false,
                'message' => "\"{$name}\" is still referenced elsewhere and cannot be removed. Deactivate it instead.",
            ], 409);
        }

        AuditLogService::log(
            'CONFIG_DELETED', 'SYSTEM_CONFIGURATION', $record::class, $id, $values, null,
            description: sprintf('%s "%s" was removed from the system configuration by %s.', $this->label($section), $name, $request->user()->name)
        );

        return response()->json(['success' => true, 'message' => "{$this->label($section)} removed.", 'data' => $this->payload()]);
    }

    private function payload(): array
    {
        $staffCounts = fn (string $column) => $this->groupedCounts('staff', $column);
        $withUsage = function ($records, $counts, ?callable $extra = null) {
            return $records->map(function (Model $record) use ($counts, $extra) {
                $usage = (int) ($counts[$record->id] ?? 0) + ($extra ? $extra($record) : 0);
                return $record->toArray() + ['usage_count' => $usage];
            })->values();
        };

        $departmentUsers = $this->groupedCounts('users', 'department_id');
        $promotionGrades = DB::table('staff_events')->select('from_grade_level_id as grade_id')->whereNotNull('from_grade_level_id')
            ->unionAll(DB::table('staff_events')->select('to_grade_level_id as grade_id')->whereNotNull('to_grade_level_id'))
            ->get()->countBy('grade_id');

        $options = [];
        foreach (array_keys(LookupOption::CATEGORIES) as $category) {
            $usage = self::OPTION_USAGE[$category];
            $counts = $this->groupedCounts($usage['table'], $usage['column']);
            $options[$category] = LookupOption::where('category', $category)->ordered()->get()
                ->map(fn (LookupOption $option) => $option->toArray() + ['usage_count' => (int) ($counts[$option->value] ?? 0)])
                ->values();
        }

        return [
            'departments' => $withUsage(Department::withCount('units')->orderBy('name')->get(), $staffCounts('department_id'), fn ($record) => (int) ($departmentUsers[$record->id] ?? 0)),
            'units' => $withUsage(Unit::with('department:id,name')->orderBy('name')->get(), $staffCounts('unit_id')),
            'designations' => $withUsage(Designation::orderBy('name')->get(), $staffCounts('designation_id')),
            'grade_levels' => $withUsage(GradeLevel::orderBy('level_number')->orderBy('name')->get(), $staffCounts('grade_level_id'), fn ($record) => (int) ($promotionGrades[$record->id] ?? 0)),
            'employment_statuses' => $withUsage(EmploymentStatus::orderBy('name')->get(), $staffCounts('employment_status_id')),
            'options' => $options,
            'option_categories' => LookupOption::CATEGORIES,
            'reserved_status_codes' => self::RESERVED_STATUS_CODES,
        ];
    }

    private function groupedCounts(string $table, string $column): \Illuminate\Support\Collection
    {
        return DB::table($table)
            ->whereNotNull($column)
            ->selectRaw($column . ' as usage_key, count(*) as usage_total')
            ->groupBy($column)
            ->pluck('usage_total', 'usage_key');
    }

    private function validated(Request $request, string $section, ?Model $record = null): array
    {
        $required = $record ? ['sometimes', 'required'] : ['required'];
        $ignore = $record?->id;

        if ($this->isOptionSection($section)) {
            $data = $request->validate([
                'value' => [...$required, 'string', 'max:' . self::OPTION_USAGE[$section]['max'], Rule::unique('lookup_options', 'value')->where('category', $section)->ignore($ignore)],
                'sort_order' => 'nullable|integer|min:0|max:9999',
                'is_active' => 'sometimes|boolean',
            ]);
            if (isset($data['value'])) {
                $data['value'] = trim($data['value']);
            }
            return $data;
        }

        $unitDepartment = $request->input('department_id', $record?->department_id);
        $rules = match ($section) {
            'departments' => [
                'name' => [...$required, 'string', 'max:255', Rule::unique('departments', 'name')->ignore($ignore)],
                'code' => [...$required, 'string', 'max:50', 'alpha_dash', Rule::unique('departments', 'code')->ignore($ignore)],
                'description' => 'nullable|string|max:1000',
            ],
            'units' => [
                'department_id' => [...$required, 'integer', 'exists:departments,id'],
                'name' => [...$required, 'string', 'max:255'],
                'code' => [...$required, 'string', 'max:50', 'alpha_dash', Rule::unique('units', 'code')->where('department_id', $unitDepartment)->ignore($ignore)],
            ],
            'designations' => [
                'name' => [...$required, 'string', 'max:255', Rule::unique('designations', 'name')->ignore($ignore)],
                'code' => [...$required, 'string', 'max:50', 'alpha_dash', Rule::unique('designations', 'code')->ignore($ignore)],
                'cadre_name' => 'nullable|string|max:255',
            ],
            'grade_levels' => [
                'name' => [...$required, 'string', 'max:50', Rule::unique('grade_levels', 'name')->ignore($ignore)],
                'level_number' => [...$required, 'integer', 'min:1', 'max:99'],
                'min_step' => 'sometimes|integer|min:1|max:15',
                'max_step' => 'sometimes|integer|min:1|max:15|gte:min_step',
                'description' => 'nullable|string|max:1000',
            ],
            'employment_statuses' => [
                'name' => [...$required, 'string', 'max:50', Rule::unique('employment_statuses', 'name')->ignore($ignore)],
                'code' => [...$required, 'string', 'max:30', 'alpha_dash', Rule::unique('employment_statuses', 'code')->ignore($ignore)],
                'is_active_nominal' => 'sometimes|boolean',
            ],
        };

        $data = $request->validate($rules + ['is_active' => 'sometimes|boolean']);
        foreach (['name', 'cadre_name', 'description'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = trim($data[$field]);
            }
        }
        if (isset($data['code'])) {
            $data['code'] = Str::upper(trim($data['code']));
        }
        if ($section === 'grade_levels' && isset($data['min_step'], $record) && !isset($data['max_step']) && $data['min_step'] > $record->max_step) {
            throw ValidationException::withMessages(['min_step' => 'The minimum step cannot exceed the maximum step.']);
        }

        return $data;
    }

    private function guardUpdate(string $section, Model $record, int $usage): void
    {
        if ($this->isOptionSection($section)) {
            if ($usage > 0 && $record->isDirty('value')) {
                throw ValidationException::withMessages(['value' => "This value is used by {$usage} record(s) and cannot be renamed. Deactivate it and add a new value instead."]);
            }
            return;
        }

        if ($record->isDirty('code') && ($usage > 0 || $this->isReserved($section, $record, true))) {
            throw ValidationException::withMessages(['code' => 'This code is in use by existing records or the system and cannot be changed.']);
        }
        if ($section === 'units' && $record->isDirty('department_id') && $usage > 0) {
            throw ValidationException::withMessages(['department_id' => 'This unit has staff assigned and cannot be moved to another department.']);
        }
        if ($record->isDirty('is_active') && !$record->is_active && $this->isReserved($section, $record, true)) {
            throw ValidationException::withMessages(['is_active' => 'This status is required by the system and cannot be deactivated.']);
        }
    }

    private function isReserved(string $section, Model $record, bool $useOriginal = false): bool
    {
        $code = $useOriginal ? $record->getOriginal('code') : $record->code;
        return $section === 'employment_statuses' && in_array($code, self::RESERVED_STATUS_CODES, true);
    }

    private function usageCount(string $section, Model $record): int
    {
        if ($this->isOptionSection($section)) {
            $usage = self::OPTION_USAGE[$section];
            return DB::table($usage['table'])->where($usage['column'], $record->getOriginal('value'))->count();
        }

        $count = DB::table('staff')->where(self::MODEL_SECTIONS[$section]['staff_column'], $record->id)->count();
        if ($section === 'departments') {
            $count += DB::table('users')->where('department_id', $record->id)->count();
        }
        if ($section === 'grade_levels') {
            $count += DB::table('staff_events')->where('from_grade_level_id', $record->id)->orWhere('to_grade_level_id', $record->id)->count();
        }

        return $count;
    }

    private function auditValues(string $section, Model $record): array
    {
        return collect($record->getAttributes())->except(['created_at', 'updated_at'])->all() + ['section' => $section];
    }

    private function describeChanges(array $previous, array $changes): string
    {
        $parts = [];
        foreach ($changes as $field => $value) {
            $from = $this->formatValue($previous[$field] ?? null);
            $to = $this->formatValue($value);
            $parts[] = $field === 'is_active'
                ? ($value ? 'Re-activated (available for new entries)' : 'Deactivated (hidden from new entries)')
                : Str::headline($field) . " changed from {$from} to {$to}";
        }

        return implode('; ', $parts) . '.';
    }

    private function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '(blank)',
            is_bool($value) => $value ? 'Yes' : 'No',
            default => '"' . $value . '"',
        };
    }

    private function displayName(string $section, Model $record): string
    {
        if ($this->isOptionSection($section)) {
            return $record->value;
        }

        return $record->code ? "{$record->name} ({$record->code})" : $record->name;
    }

    private function label(string $section): string
    {
        return $this->isOptionSection($section)
            ? self::OPTION_LABELS[$section]
            : self::MODEL_SECTIONS[$section]['label'];
    }

    private function findRecord(string $section, int $id): Model
    {
        return $this->isOptionSection($section)
            ? LookupOption::where('category', $section)->findOrFail($id)
            : $this->modelClass($section)::findOrFail($id);
    }

    private function modelClass(string $section): string
    {
        return self::MODEL_SECTIONS[$section]['model'];
    }

    private function isOptionSection(string $section): bool
    {
        return array_key_exists($section, LookupOption::CATEGORIES);
    }

    private function ensureSection(string $section): void
    {
        abort_unless(isset(self::MODEL_SECTIONS[$section]) || $this->isOptionSection($section), 404, 'Unknown configuration list.');
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffEvent;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class StaffEventController extends Controller
{
    private const TYPES = ['PROMOTION', 'LEAVE', 'DISCIPLINARY', 'TRAINING'];
    private const DISCIPLINARY_ACTIONS = [
        'Dismissal',
        'Removal on grounds of inefficiency',
        'Discharge on grounds of general unsuitability for further employment',
        'Reduction in rank',
        'Reduction in salary',
        'Withholding of increment',
        'Withholding of salary',
        'Surcharge',
        'Reprimand',
        'Interdiction',
        'Suspension',
        'Deferment of increment',
        'Formal letter of advice / Written warning',
        'Fine',
        'Termination of appointment',
        'Retirement in public interest',
    ];

    public function index(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(self::TYPES)]])['type'];
        $this->authorizeType($request, $type);
        $events = StaffEvent::with([
            'staff:id,staff_number,ogun_employee_number,title,surname,first_name,middle_name,photo_path,grade_level_id,is_active',
            'staff.gradeLevel:id,name',
            'fromGradeLevel:id,name',
            'toGradeLevel:id,name',
            'creator:id,name,username',
        ])->whereHas('staff')->where('type', $type)->orderByDesc('occurred_on')->paginate(max(1, min(100, $request->integer('per_page', 25))));
        return response()->json(['success' => true, 'data' => $events]);
    }

    public function forStaff(Request $request, Staff $staff): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(self::TYPES)]])['type'];
        $this->authorizeType($request, $type);
        return response()->json(['success' => true, 'data' => $staff->events()->where('type', $type)->with(['fromGradeLevel:id,name', 'toGradeLevel:id,name', 'creator:id,name,username'])->get()]);
    }

    public function store(Request $request, Staff $staff): JsonResponse
    {
        $this->authorizeType($request, $request->input('type'));
        $data = $this->validated($request);
        if ($data['type'] === 'PROMOTION' && (int) $data['from_grade_level_id'] !== (int) $staff->grade_level_id) {
            throw ValidationException::withMessages(['from_grade_level_id' => 'The current grade must match the officer\'s current staff record.']);
        }
        $data['staff_id'] = $staff->id;
        $data['created_by'] = $request->user()->id;
        if (in_array($data['type'], ['LEAVE', 'TRAINING'], true)) {
            $data['occurred_on'] = $data['start_date'];
        }
        if ($data['type'] === 'LEAVE') {
            $data['leave_days'] = (int) Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;
        }
        $event = StaffEvent::create($data);
        if ($event->type === 'PROMOTION') {
            $previous = $staff->only(['grade_level_id', 'date_of_last_promotion']);
            $staff->update(['grade_level_id' => $event->to_grade_level_id, 'date_of_last_promotion' => $event->occurred_on]);
            AuditLogService::log('STAFF_PROMOTED', 'PROMOTIONS', Staff::class, $staff->id, $previous, $staff->only(['grade_level_id', 'date_of_last_promotion']));
        }
        AuditLogService::log('STAFF_EVENT_CREATED', $event->type, StaffEvent::class, $event->id, null, $this->auditValues($event));
        return response()->json(['success' => true, 'data' => $event->load(['fromGradeLevel:id,name', 'toGradeLevel:id,name'])], 201);
    }

    public function update(Request $request, StaffEvent $event): JsonResponse
    {
        $this->authorizeType($request, $event->type);
        if ($request->input('type') && $request->input('type') !== $event->type) {
            throw ValidationException::withMessages(['type' => 'An event cannot change module type.']);
        }
        $data = $this->validated($request, true, $event->type);
        $staff = $event->staff;
        $previous = $this->auditValues($event);
        if (in_array($event->type, ['LEAVE', 'TRAINING'], true) && isset($data['start_date'])) {
            $data['occurred_on'] = $data['start_date'];
        }
        if ($event->type === 'LEAVE' && isset($data['start_date'], $data['end_date'])) {
            $data['leave_days'] = (int) Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;
        }
        $event->update($data);
        if ($event->type === 'PROMOTION' && $staff->grade_level_id === $previous['to_grade_level_id']) {
            $staff->update(['grade_level_id' => $event->to_grade_level_id, 'date_of_last_promotion' => $event->occurred_on]);
        }
        AuditLogService::log('STAFF_EVENT_UPDATED', $event->type, StaffEvent::class, $event->id, $previous, $this->auditValues($event));
        return response()->json(['success' => true, 'data' => $event->fresh()->load(['fromGradeLevel:id,name', 'toGradeLevel:id,name'])]);
    }

    public function destroy(Request $request, StaffEvent $event): JsonResponse
    {
        $this->authorizeType($request, $event->type);
        $values = $this->auditValues($event);
        if ($event->type === 'PROMOTION' && $event->staff->grade_level_id === $event->to_grade_level_id) {
            $previousPromotion = $event->staff->events()->where('type', 'PROMOTION')->where('id', '!=', $event->id)
                ->whereDate('occurred_on', '<', $event->occurred_on)->orderByDesc('occurred_on')->first();
            $event->staff->update([
                'grade_level_id' => $previousPromotion?->to_grade_level_id ?? $event->from_grade_level_id,
                'date_of_last_promotion' => $previousPromotion?->occurred_on,
            ]);
        }
        $event->delete();
        AuditLogService::log('STAFF_EVENT_DELETED', $event->type, StaffEvent::class, $event->id, $values, null);
        return response()->json(['success' => true]);
    }

    private function validated(Request $request, bool $updating = false, ?string $fixedType = null): array
    {
        $type = $fixedType ?? $request->input('type');
        if (!in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose a supported staff history module.']);
        }
        $rules = [
            'type' => [$updating ? 'sometimes' : 'required', Rule::in([$type])],
            'title' => [$updating ? 'sometimes' : 'required', 'string', 'max:190'],
            'occurred_on' => in_array($type, ['LEAVE', 'TRAINING'], true) ? 'nullable|date' : [$updating ? 'sometimes' : 'required', 'date'],
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'resumed_on' => 'nullable|date|after_or_equal:start_date',
            'from_grade_level_id' => 'nullable|exists:grade_levels,id',
            'to_grade_level_id' => 'nullable|exists:grade_levels,id|different:from_grade_level_id',
            'leave_type' => 'nullable|string|max:80',
            'leave_days' => 'prohibited',
            'disciplinary_action' => ['nullable', Rule::in(self::DISCIPLINARY_ACTIONS)],
            'reason' => 'nullable|string|max:10000',
            'staff_response' => 'nullable|string|max:10000',
            'action_taken' => 'nullable|string|max:10000',
            'current_state' => 'nullable|string|max:120',
            'training_type' => 'nullable|string|max:120',
            'provider' => 'nullable|string|max:190',
            'qualification_obtained' => 'nullable|string|max:190',
            'circular_reference' => 'nullable|string|max:120',
            'remarks' => 'nullable|string|max:10000',
        ];
        if (!$updating) {
            if ($type === 'PROMOTION') $rules += ['from_grade_level_id' => 'required|exists:grade_levels,id', 'to_grade_level_id' => 'required|exists:grade_levels,id|different:from_grade_level_id'];
            if ($type === 'LEAVE') $rules += ['leave_type' => 'required|string|max:80', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date'];
            if ($type === 'DISCIPLINARY') $rules += ['disciplinary_action' => ['required', Rule::in(self::DISCIPLINARY_ACTIONS)], 'reason' => 'required|string|max:10000', 'action_taken' => 'required|string|max:10000', 'current_state' => 'required|string|max:120'];
            if ($type === 'TRAINING') $rules += ['training_type' => 'required|string|max:120', 'start_date' => 'required|date', 'end_date' => 'required|date|after_or_equal:start_date'];
        }
        return $request->validate($rules);
    }

    private function authorizeType(Request $request, ?string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose a supported staff history module.']);
        }
        $role = $request->user()?->role?->code;
        if ($type === 'DISCIPLINARY' && !in_array($role, ['SUPER_ADMIN', 'HR_ADMIN', 'HR_OFFICER', 'DEPT_ADMIN'], true)) {
            abort(403, 'Disciplinary records are restricted to HR and department administrators.');
        }
        if ($type === 'DISCIPLINARY' && !$request->isMethod('get') && !in_array($role, ['SUPER_ADMIN', 'HR_ADMIN'], true)) {
            abort(403, 'Only super administrators and HR administrators may modify disciplinary records.');
        }
    }

    private function auditValues(StaffEvent $event): array
    {
        return $event->only([
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
        ]);
    }
}

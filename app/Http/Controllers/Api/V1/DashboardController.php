<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\Department;
use App\Models\StaffEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function statistics(Request $request): JsonResponse
    {
        $today = now()->toDateString();
        $sixMonthsAgo = now()->subMonths(6)->toDateString();
        $yearStart = now()->startOfYear()->toDateString();
        $canViewDiscipline = in_array($request->user()?->role?->code, ['SUPER_ADMIN', 'HR_ADMIN', 'HR_OFFICER', 'DEPT_ADMIN'], true);

        $currentLeave = fn (Builder $leave) => $leave->where('type', 'LEAVE')
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->where(fn (Builder $resumed) => $resumed->whereNull('resumed_on')->orWhereDate('resumed_on', '>', $today));

        // whereHas('staff') applies the Staff department scope, so dept admins only see their own department.
        $events = fn (string $type) => StaffEvent::where('type', $type)->whereHas('staff');

        $byStatus = Staff::query()
            ->join('employment_statuses', 'employment_statuses.id', '=', 'staff.employment_status_id')
            ->select('employment_statuses.code', DB::raw('count(*) as count'))
            ->groupBy('employment_statuses.code')
            ->pluck('count', 'code');

        $gradeBands = Staff::query()
            ->join('grade_levels', 'grade_levels.id', '=', 'staff.grade_level_id')
            ->selectRaw("CASE WHEN grade_levels.level_number BETWEEN 1 AND 6 THEN 'GL 01-06' WHEN grade_levels.level_number BETWEEN 7 AND 12 THEN 'GL 07-12' WHEN grade_levels.level_number BETWEEN 13 AND 17 THEN 'GL 13-17' ELSE 'Other / Special' END as band, count(*) as count")
            ->groupBy('band')
            ->pluck('count', 'band');

        $byCadre = Staff::query()
            ->join('designations', 'designations.id', '=', 'staff.designation_id')
            ->select(DB::raw("COALESCE(designations.cadre_name, 'Unspecified') as cadre"), DB::raw('count(*) as count'))
            ->groupBy('cadre')
            ->orderByDesc('count')
            ->pluck('count', 'cadre');

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'active_staff' => Staff::count(),
                    'active_records' => Staff::where('is_active', true)->count(),
                    'inactive_records' => Staff::where('is_active', false)->count(),
                    'departments_count' => Department::count(),
                    'retirements_next_12m' => Staff::whereBetween('expected_retirement_date', [now(), now()->addYear()])->count(),
                    'on_leave_now' => Staff::where(fn (Builder $query) => $query
                        ->whereHas('status', fn (Builder $status) => $status->where('code', 'ON_LEAVE'))
                        ->orWhereHas('events', $currentLeave))->count(),
                    'promoted_last_6m' => Staff::where(fn (Builder $query) => $query
                        ->whereDate('date_of_last_promotion', '>=', $sixMonthsAgo)
                        ->orWhereHas('events', fn (Builder $promotion) => $promotion->where('type', 'PROMOTION')->whereDate('occurred_on', '>=', $sixMonthsAgo)))->count(),
                    'posted_to_mdas' => (int) ($byStatus['POSTED'] ?? 0),
                    'trainings_this_year' => $events('TRAINING')->whereDate('start_date', '>=', $yearStart)->count(),
                    'missing_photos' => Staff::where(fn (Builder $query) => $query->whereNull('photo_path')->orWhere('photo_path', ''))->count(),
                    'disciplinary_last_12m' => $canViewDiscipline
                        ? $events('DISCIPLINARY')->whereDate('occurred_on', '>=', now()->subYear()->toDateString())->count()
                        : null,
                ],
                'by_gender' => Staff::select('gender', DB::raw('count(*) as count'))->groupBy('gender')->pluck('count', 'gender'),
                'by_department' => Staff::select('department_id', DB::raw('count(*) as count'))->groupBy('department_id')->pluck('count', 'department_id'),
                'by_status' => $byStatus,
                'by_grade_band' => $gradeBands,
                'by_cadre' => $byCadre,
            ]
        ]);
    }
}

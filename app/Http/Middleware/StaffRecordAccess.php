<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Models\StaffEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class StaffRecordAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $staff = $request->route('staff');
        $event = $request->route('event');
        if ($event) {
            $event = $event instanceof StaffEvent ? $event : StaffEvent::findOrFail($event);
            $staff = $event->staff_id;
        }
        if (!$staff) return $next($request);

        $id = $staff instanceof Staff ? $staff->id : $staff;
        $mutating = in_array($request->route()->getActionMethod(), [
            'store', 'update', 'destroy', 'storeRating', 'destroyRating', 'storeDocument', 'destroyDocument', 'setActivity',
        ], true);
        if (!$mutating) {
            Staff::findOrFail($id);
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next, $id) {
            $staff = Staff::lockForUpdate()->findOrFail($id);
            if (!$staff->is_active && $request->route()->getActionMethod() !== 'setActivity') {
                return response()->json(['success' => false, 'message' => 'Inactive staff records are read-only. Reactivate the staff member before making changes.'], 409);
            }
            if ($request->route('staff') instanceof Staff) {
                $request->route()->setParameter('staff', $staff);
            }
            return $next($request);
        });
    }
}

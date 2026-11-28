<?php

namespace App\Services;

use App\Models\Staff;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RetirementRecorder
{
    /**
     * Writes a one-time system audit entry for every staff member whose statutory retirement date has arrived.
     */
    public function recordDueRetirements(): int
    {
        $recorded = 0;
        $today = Carbon::today();

        Staff::withoutGlobalScopes()
            ->with('department:id,name')
            ->whereNull('deleted_at')
            ->whereNull('retirement_recorded_at')
            ->whereDate('expected_retirement_date', '<=', $today)
            ->orderBy('id')
            ->chunkById(200, function ($retirees) use (&$recorded) {
                foreach ($retirees as $staff) {
                    // The conditional update is the idempotency guard when several requests run at once.
                    $claimed = DB::table('staff')
                        ->where('id', $staff->id)
                        ->whereNull('retirement_recorded_at')
                        ->update(['retirement_recorded_at' => now()]);
                    if ($claimed === 0) {
                        continue;
                    }

                    $reason = $staff->retirement_reason;
                    $trigger = $reason === 'Age 60' ? 'attaining the statutory age of 60 years' : 'completing 35 years of service';
                    $retirementDate = $staff->expected_retirement_date->toDateString();

                    AuditLogService::logSystem(
                        'STAFF_RETIRED',
                        'STAFF_LIFECYCLE',
                        Staff::class,
                        $staff->id,
                        ['record_status' => (bool) $staff->getRawOriginal('is_active') ? 'active' : 'suspended'],
                        [
                            'record_status' => 'retired',
                            'staff_number' => $staff->staff_number,
                            'staff_name' => $staff->full_name,
                            'department' => $staff->department?->name,
                            'retirement_date' => $retirementDate,
                            'retirement_reason' => $reason,
                            'date_of_birth' => $staff->date_of_birth?->toDateString(),
                            'date_of_first_appointment' => $staff->date_of_first_appointment?->toDateString(),
                        ],
                        sprintf(
                            '%s was automatically switched to Retired by the system on reaching the statutory retirement date (%s) after %s. The record is now permanently read-only.',
                            AuditLogService::staffLabel($staff),
                            $retirementDate,
                            $trigger
                        )
                    );
                    $recorded++;
                }
            });

        return $recorded;
    }
}

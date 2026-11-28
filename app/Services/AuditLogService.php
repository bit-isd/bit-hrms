<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Staff;
use App\Models\StaffConfidentialDocument;
use App\Models\StaffDocument;
use App\Models\StaffEvent;
use App\Models\StaffPerformanceRating;
use App\Models\StaffPosting;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuditLogService
{
    private const STAFF_CHILD_RECORDS = [
        StaffEvent::class,
        StaffDocument::class,
        StaffConfidentialDocument::class,
        StaffPerformanceRating::class,
        StaffPosting::class,
    ];

    public static function log(string $action, string $module, ?string $recordType = null, ?int $recordId = null, ?array $previousValues = null, ?array $newValues = null, ?User $actor = null, ?string $actorUsername = null, ?string $ipAddress = null, ?string $description = null): void
    {
        $user = $actor ?? Auth::user();
        AuditLog::create([
            'user_id' => $user?->id,
            'username' => $user?->username ?? $actorUsername ?? 'SYSTEM',
            'action' => $action,
            'module' => $module,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'previous_values' => $previousValues,
            'new_values' => $newValues,
            'description' => $description ?? self::describe($action, $recordType, $recordId, $newValues ?? $previousValues ?? []),
            'ip_address' => $ipAddress ?? Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }

    /**
     * Records an action performed automatically by the application rather than by a signed-in user.
     */
    public static function logSystem(string $action, string $module, ?string $recordType, ?int $recordId, ?array $previousValues, ?array $newValues, string $description): void
    {
        AuditLog::create([
            'user_id' => null,
            'username' => 'SYSTEM',
            'action' => $action,
            'module' => $module,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'previous_values' => $previousValues,
            'new_values' => $newValues,
            'description' => $description,
            'ip_address' => null,
            'user_agent' => 'BIT-HRMS automated lifecycle engine',
            'created_at' => now(),
        ]);
    }

    public static function staffLabel(Staff $staff): string
    {
        return trim("{$staff->title} {$staff->surname} {$staff->first_name}") . " ({$staff->staff_number})";
    }

    private static function describe(string $action, ?string $recordType, ?int $recordId, array $values): string
    {
        $summary = Str::ucfirst(Str::lower(str_replace('_', ' ', $action)));
        $subject = self::subject($recordType, $recordId, $values);

        return $subject ? "{$summary} — {$subject}." : "{$summary}.";
    }

    private static function subject(?string $recordType, ?int $recordId, array $values): ?string
    {
        $staffId = $recordType === Staff::class ? $recordId : ($values['staff_id'] ?? null);
        if (!$staffId && $recordId && in_array($recordType, self::STAFF_CHILD_RECORDS, true)) {
            $staffId = $recordType::query()->whereKey($recordId)->value('staff_id');
        }

        if ($staffId) {
            $staff = Staff::withoutGlobalScopes()->find($staffId, ['id', 'title', 'surname', 'first_name', 'staff_number']);
            return $staff ? 'staff ' . self::staffLabel($staff) : "staff record #{$staffId}";
        }

        if ($recordType === User::class && $recordId) {
            $user = User::query()->find($recordId, ['id', 'name', 'username']);
            return $user ? "user account {$user->name} ({$user->username})" : "user account #{$recordId}";
        }

        return null;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Staff;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffProfileController extends Controller
{
    public function printData(Request $request, Staff $staff): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->profileData($request, $staff)]);
    }

    public function export(Request $request, Staff $staff): StreamedResponse
    {
        $data = $this->profileData($request, $staff);
        $rows = [];
        foreach ($data['staff'] as $key => $value) {
            if (is_scalar($value) || $value === null) $rows[] = ['Staff details', $key, $value];
        }
        foreach (['postings', 'documents', 'events', 'confidential_ratings', 'confidential_documents'] as $section) {
            foreach ($data[$section] ?? [] as $record) {
                foreach ($record as $key => $value) {
                    if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    if (is_scalar($value) || $value === null) $rows[] = [$section, $key, $value];
                }
                $rows[] = ['', '', ''];
            }
        }
        AuditLogService::log('STAFF_PROFILE_EXPORTED', 'STAFF_PROFILE', Staff::class, $staff->id, null, ['confidential_included' => $data['confidential_included']]);
        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($handle, ['Section', 'Field', 'Value']);
            foreach ($rows as $row) {
                $row = array_map(fn($value) => is_string($value) && preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value, $row);
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 'staff-profile-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $staff->staff_number) . '-' . now()->format('Ymd') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function profileData(Request $request, Staff $staff): array
    {
        $staff->load(['department', 'unit', 'designation', 'gradeLevel', 'status', 'postings', 'documents']);
        $role = $request->user()?->role?->code;
        $isAdmin = in_array($role, ['SUPER_ADMIN', 'HR_ADMIN'], true);
        $events = $staff->events()->with(['fromGradeLevel:id,name', 'toGradeLevel:id,name'])->get();
        if (!$isAdmin) $events = $events->where('type', '!=', 'DISCIPLINARY')->values();
        $result = [
            'staff' => $staff->toArray(),
            'postings' => $staff->postings->toArray(),
            'documents' => $staff->documents->map(fn($doc) => $doc->only(['document_type', 'title', 'file_name', 'file_size', 'mime_type', 'file_path', 'created_at']))->toArray(),
            'events' => $events->toArray(),
            'confidential_included' => false,
        ];
        $result['staff']['department_name'] = $staff->department?->name;
        $result['staff']['unit_name'] = $staff->unit?->name;
        $result['staff']['designation_name'] = $staff->designation?->name;
        $result['staff']['grade_level_name'] = $staff->gradeLevel?->name;
        $result['staff']['employment_status'] = $staff->status?->name;
        if ($isAdmin) {
            $credentials = $request->validate(['password' => 'required|string|max:255']);
            if (!Hash::check($credentials['password'], $request->user()->password)) {
                throw ValidationException::withMessages(['password' => 'The administrator password is incorrect.']);
            }
            $result['confidential_ratings'] = $staff->performanceRatings()->get()->makeHidden(['entered_by'])->toArray();
            $result['confidential_documents'] = $staff->confidentialDocuments()->get()->map(fn($doc) => $doc->only(['title', 'category', 'file_name', 'file_size', 'mime_type', 'created_at']))->toArray();
            $result['confidential_included'] = true;
            AuditLogService::log('CONFIDENTIAL_PROFILE_EXPORTED', 'CONFIDENTIAL', Staff::class, $staff->id, null, ['ratings_count' => count($result['confidential_ratings']), 'documents_count' => count($result['confidential_documents'])]);
        }
        return $result;
    }
}

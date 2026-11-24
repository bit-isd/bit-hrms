<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\Department;
use App\Models\Unit;
use App\Models\Designation;
use App\Models\GradeLevel;
use App\Models\EmploymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class StaffService
{
    public function createStaff(array $data): Staff
    {
        return DB::transaction(function () use ($data) {
            $data['title'] = $data['title'] ?? 'Mr.';
            $data['current_station'] = $data['current_station'] ?? 'BIT Headquarters, Abeokuta';
            $dob = $data['date_of_birth'];
            $dofa = $data['date_of_first_appointment'];
            $data['expected_retirement_date'] = Staff::calculateRetirementDate($dob, $dofa)->toDateString();
            $data['created_by'] = Auth::id() ?? 1;
            $data['updated_by'] = Auth::id() ?? 1;

            $staff = Staff::create($data);

            AuditLogService::log(
                action: 'STAFF_CREATED',
                module: 'STAFF_MANAGEMENT',
                recordType: Staff::class,
                recordId: $staff->id,
                previousValues: null,
                newValues: [
                    'staff_number' => $staff->staff_number,
                    'name' => $staff->full_name,
                    'department_id' => $staff->department_id,
                    'designation_id' => $staff->designation_id,
                    'grade_level_id' => $staff->grade_level_id,
                ]
            );

            return $staff;
        });
    }

    public function updateStaff(Staff $staff, array $data): Staff
    {
        return DB::transaction(function () use ($staff, $data) {
            if (isset($data['date_of_birth']) || isset($data['date_of_first_appointment'])) {
                $dob = $data['date_of_birth'] ?? $staff->date_of_birth->toDateString();
                $dofa = $data['date_of_first_appointment'] ?? $staff->date_of_first_appointment->toDateString();
                $data['expected_retirement_date'] = Staff::calculateRetirementDate($dob, $dofa)->toDateString();
            }

            $staff->fill($data);
            $changes = $staff->getDirty();
            $previous = array_intersect_key($staff->getRawOriginal(), $changes);
            $staff->updated_by = Auth::id() ?? 1;
            $staff->save();

            if ($changes) {
                AuditLogService::log(
                    action: 'STAFF_UPDATED',
                    module: 'STAFF_MANAGEMENT',
                    recordType: Staff::class,
                    recordId: $staff->id,
                    previousValues: $previous,
                    newValues: $changes
                );
            }

            return $staff;
        });
    }

    /**
     * Batch import rows from CSV array
     */
    public function importFromRows(array $rows): array
    {
        $errors = [];
        $validRecords = [];

        // Lookup maps for validation
        $depts = Department::all()->keyBy(fn($d) => strtoupper($d->code))->toArray();
        $units = Unit::all()->keyBy(fn($u) => strtoupper($u->code))->toArray();
        $designations = Designation::all()->keyBy(fn($d) => strtoupper($d->name))->toArray();
        $gradeLevels = GradeLevel::all()->keyBy(fn($g) => strtoupper($g->name))->toArray();
        $statuses = EmploymentStatus::all()->keyBy(fn($s) => strtoupper($s->code))->toArray();

        $existingStaffNumbers = Staff::withTrashed()->pluck('staff_number')->map(fn($v) => strtoupper($v))->toArray();
        $existingEmails = Staff::withTrashed()->pluck('official_email')->map(fn($v) => strtolower($v))->toArray();
        $existingOgunNumbers = Staff::withTrashed()->whereNotNull('ogun_employee_number')->pluck('ogun_employee_number')->map(fn ($v) => strtoupper($v))->toArray();

        foreach ($rows as $index => $row) {
            $rowNum = $index + 2; // header is row 1
            $rowErrors = [];

            $row = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $row);
            $validator = Validator::make($row, [
                'staff_number' => 'required|string|max:50',
                'surname' => 'required|string|max:100',
                'first_name' => 'required|string|max:100',
                'middle_name' => 'nullable|string|max:100',
                'title' => 'nullable|string|max:20',
                'gender' => 'required|in:Male,Female',
                'official_email' => 'required|email|max:150',
                'date_of_birth' => 'required|date_format:Y-m-d',
                'date_of_first_appointment' => 'required|date_format:Y-m-d',
                'date_of_present_appointment' => 'required|date_format:Y-m-d|after_or_equal:date_of_first_appointment',
                'date_of_confirmation' => 'nullable|date_format:Y-m-d|after_or_equal:date_of_first_appointment',
                'date_of_last_promotion' => 'nullable|date_format:Y-m-d|after_or_equal:date_of_first_appointment',
                'date_of_last_deployment' => 'nullable|date_format:Y-m-d|after_or_equal:date_of_first_appointment',
                'step' => 'nullable|integer|min:1|max:15',
                'ogun_employee_number' => 'nullable|string|max:50',
                'file_number' => 'nullable|string|max:50',
                'ippis_number' => 'nullable|string|max:50',
                'phone_number' => 'nullable|string|max:30',
                'personal_email' => 'nullable|email|max:150',
                'place_of_birth' => 'nullable|string|max:150',
                'state_of_origin' => 'nullable|string|max:100',
                'lga_of_origin' => 'nullable|string|max:100',
                'qualification' => 'nullable|string|max:100',
                'residential_address' => 'nullable|string|max:1000',
                'current_station' => 'nullable|string|max:150',
                'remarks' => 'nullable|string|max:500',
                'is_active' => 'prohibited',
            ]);
            $rowErrors = $validator->errors()->all();
            $row = array_map(fn ($value) => is_scalar($value) ? (string) $value : null, $row);

            // Trim inputs
            $staffNumber = trim($row['staff_number'] ?? '');
            $email = trim($row['official_email'] ?? '');
            $deptCode = strtoupper(trim($row['department_code'] ?? ''));
            $unitCode = strtoupper(trim($row['unit_code'] ?? ''));
            $desigName = strtoupper(trim($row['designation'] ?? ''));
            $gradeName = strtoupper(trim($row['grade_level'] ?? ''));
            $statusName = strtoupper(trim($row['status'] ?? 'ACTIVE'));
            $ogunNumber = $row['ogun_employee_number'] ?? null;

            // Basic validation
            if (empty($staffNumber)) $rowErrors[] = "Staff Number is required.";

            // Uniqueness
            if (in_array(strtoupper($staffNumber), $existingStaffNumbers)) {
                $rowErrors[] = "Staff Number '{$staffNumber}' already exists in the system.";
            }
            if (in_array(strtolower($email), $existingEmails)) {
                $rowErrors[] = "Email '{$email}' is already in use.";
            }
            if ($ogunNumber && in_array(strtoupper($ogunNumber), $existingOgunNumbers, true)) {
                $rowErrors[] = "OG Number '{$ogunNumber}' is already in use.";
            }

            // Relationship existence
            if (!isset($depts[$deptCode])) {
                $rowErrors[] = "Unknown Department code '{$deptCode}'. Available: " . implode(', ', array_keys($depts));
            }
            if (!isset($units[$unitCode])) {
                $rowErrors[] = "Unknown Unit code '{$unitCode}'.";
            } elseif (isset($depts[$deptCode]) && $units[$unitCode]['department_id'] !== $depts[$deptCode]['id']) {
                $rowErrors[] = "Unit '{$unitCode}' does not belong to department '{$deptCode}'.";
            }
            if (!isset($designations[$desigName])) {
                $rowErrors[] = "Unknown Designation '{$desigName}'.";
            }
            if (!isset($gradeLevels[$gradeName])) {
                $rowErrors[] = "Unknown Grade Level '{$gradeName}'. (e.g. GL 08, GL 10, GL 14)";
            }
            if (!isset($statuses[$statusName])) {
                $rowErrors[] = "Unknown employment status '{$statusName}'. Use a status code such as ACTIVE or POSTED.";
            }

            if (!empty($rowErrors)) {
                $errors[] = [
                    'row' => $rowNum,
                    'staff_number' => $staffNumber ?: 'MISSING',
                    'name' => ($row['surname'] ?? '') . ' ' . ($row['first_name'] ?? ''),
                    'errors' => $rowErrors,
                ];
            } else {
                $dob = $row['date_of_birth'];
                $dofa = $row['date_of_first_appointment'];
                $retirementDate = Staff::calculateRetirementDate($dob, $dofa)->toDateString();

                $optionalFields = [
                    'date_of_confirmation', 'date_of_last_promotion', 'date_of_last_deployment',
                    'phone_number', 'personal_email', 'place_of_birth', 'state_of_origin',
                    'lga_of_origin', 'qualification', 'residential_address', 'remarks',
                ];
                $optional = array_fill_keys($optionalFields, null);
                foreach ($optionalFields as $field) $optional[$field] = $row[$field] ?? null;
                $validRecords[] = [
                    'staff_number' => $staffNumber,
                    'ogun_employee_number' => $row['ogun_employee_number'] ?? null,
                    'file_number' => $row['file_number'] ?? null,
                    'ippis_number' => $row['ippis_number'] ?? null,
                    'title' => $row['title'] ?? 'Mr.',
                    'surname' => trim($row['surname']),
                    'first_name' => trim($row['first_name']),
                    'middle_name' => trim($row['middle_name'] ?? ''),
                    'gender' => $row['gender'],
                    'date_of_birth' => $dob,
                    'official_email' => $email,
                    'department_id' => $depts[$deptCode]['id'],
                    'unit_id' => $units[$unitCode]['id'],
                    'designation_id' => $designations[$desigName]['id'],
                    'grade_level_id' => $gradeLevels[$gradeName]['id'],
                    'step' => intval($row['step'] ?? 1),
                    'employment_status_id' => $statuses[$statusName]['id'],
                    'current_station' => $row['current_station'] ?? 'BIT Headquarters, Abeokuta',
                    'is_active' => true,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                    'date_of_first_appointment' => $dofa,
                    'date_of_present_appointment' => $row['date_of_present_appointment'],
                    'expected_retirement_date' => $retirementDate,
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + $optional;

                $existingStaffNumbers[] = strtoupper($staffNumber);
                if (!empty($email)) $existingEmails[] = strtolower($email);
                if ($ogunNumber) $existingOgunNumbers[] = strtoupper($ogunNumber);
            }
        }

        // Insert valid records in chunks within a transaction
        if (!empty($validRecords)) {
            DB::transaction(function () use ($validRecords) {
                foreach (array_chunk($validRecords, 50) as $chunk) {
                    Staff::insert($chunk);
                }

                AuditLogService::log(
                    action: 'NOMINAL_ROLL_IMPORTED',
                    module: 'DATA_IMPORT',
                    newValues: [
                        'imported_count' => count($validRecords)
                    ]
                );
            });
        }

        return [
            'success' => count($errors) === 0,
            'total_rows' => count($rows),
            'imported_count' => count($validRecords),
            'failed_count' => count($errors),
            'errors' => $errors,
        ];
    }
}

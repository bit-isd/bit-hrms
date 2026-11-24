<?php

namespace Database\Seeders;

use App\Models\GradeLevel;
use App\Models\Staff;
use App\Models\StaffConfidentialDocument;
use App\Models\StaffDocument;
use App\Models\StaffEvent;
use App\Models\StaffPerformanceRating;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Enriches every staff record with sample photos, personal-folder documents,
 * promotion, leave, training and disciplinary history and performance ratings.
 *
 * Source images live in storage/app/public/staff_documents/samples (committed);
 * each staff member receives its own copy so in-app deletions never touch the originals.
 */
class StaffSampleRecordsSeeder extends Seeder
{
    private const SAMPLES = 'staff_documents/samples';

    private const PHOTOS = [
        'Female' => ['girl1.png', 'girl2.png'],
        'Male' => ['man1.png', 'man2.png', 'man3.png'],
    ];

    private const APPOINTMENT_LETTERS = [
        '1_appointment_letter_1771611525.jpeg',
        '2_appointment_letter_1771546070.jpeg',
        '2_appointment_letter_1771613536.jpeg',
        '3_appointment_letter_1772030128.jpeg',
    ];

    private const PROMOTION_LETTERS = [
        '1_last_promotion_letter_1771611525.jpeg',
        '2_last_promotion_letter_1771546070.jpg',
        '2_last_promotion_letter_1771613536.jpeg',
        '3_last_promotion_letter_1772030128.jpeg',
    ];

    private const ARCHIVED_PHOTOS = [
        '1_passport_photo_1771611525.jpeg',
        '2_passport_photo_1771546070.jpeg',
        '2_passport_photo_1771613536.jpeg',
        '3_passport_photo_1772030128.jpeg',
    ];

    private const BIRTH_PLACES = ['Abeokuta', 'Ijebu-Ode', 'Sagamu', 'Ota', 'Ilaro', 'Ikenne', 'Ifo', 'Lagos', 'Ibadan'];

    private const ADDRESSES = [
        '14, Ibara Housing Estate, Abeokuta',
        '7, Oke-Ilewo Road, Abeokuta',
        '22, Asero Estate, Abeokuta',
        '3, Isale-Igbein Street, Abeokuta',
        '41, Quarry Road, Ibara, Abeokuta',
        '9, Kemta Estate, Idi-Aba, Abeokuta',
        '18, Adigbe Road, Abeokuta',
        '5, OGD Estate, Lafenwa, Abeokuta',
    ];

    private const TRAININGS = [
        ['Cloud Infrastructure Fundamentals', 'Professional certification', 'Ogun State Civil Service Training School', 'Certificate of Completion'],
        ['Cybersecurity Awareness for Public Officers', 'In-service workshop', 'National Information Technology Development Agency (NITDA)', 'Certificate of Attendance'],
        ['Records Management and Data Protection', 'Seminar', 'Nigeria Data Protection Commission', 'NDPC Compliance Certificate'],
        ['Project Management Professional (PMP) Preparation', 'Professional course', 'Project Management Institute, Lagos Chapter', 'PMP Exam Eligibility'],
        ['Leadership and Change Management', 'Management course', 'Administrative Staff College of Nigeria (ASCON)', 'ASCON Certificate'],
        ['Database Administration with PostgreSQL', 'Technical training', 'Bureau of Information Technology Academy', 'Internal Certification'],
    ];

    private const DISCIPLINARY = [
        [
            'title' => 'Query for unauthorised absence from duty',
            'action' => 'Reprimand',
            'reason' => 'Officer was absent from duty for three consecutive working days without approval or notification to the Head of Unit.',
            'response' => 'Officer explained that a family emergency prevented timely notification and tendered supporting evidence.',
            'outcome' => 'Letter of reprimand issued and placed on personal file. Officer cautioned to follow leave procedures.',
            'state' => 'Active - In Service',
        ],
        [
            'title' => 'Query for breach of ICT acceptable-use policy',
            'action' => 'Withholding of increment',
            'reason' => 'Installation of unlicensed software on a government workstation, contrary to the State ICT acceptable-use policy.',
            'response' => 'Officer admitted the breach and stated the software was required to complete an urgent assignment.',
            'outcome' => 'Annual increment withheld for one year following recommendation of the Senior Staff Disciplinary Committee.',
            'state' => 'Active - In Service',
        ],
    ];

    public function run(): void
    {
        $publicDisk = Storage::disk('public');
        $privateDisk = Storage::build(['driver' => 'local', 'root' => storage_path('app/private')]);
        $adminId = User::where('username', 'superadmin')->value('id');
        $gradeLevels = GradeLevel::where('name', 'like', 'GL %')->get()->keyBy('level_number');

        $staffMembers = Staff::with('gradeLevel')->orderBy('id')->get();

        foreach ($staffMembers as $index => $staff) {
            $this->seedRandom($staff, 'documents');
            $folder = "staff_documents/{$staff->id}";
            $firstAppointment = Carbon::parse($staff->date_of_first_appointment);

            $photo = $this->pick(self::PHOTOS[$staff->gender] ?? self::PHOTOS['Male']);
            $photoPath = $this->copySample($publicDisk, "photos/{$photo}", "{$folder}/passport-photo." . pathinfo($photo, PATHINFO_EXTENSION));
            $this->document($staff, 'PASSPORT_PHOTO', 'Official passport photograph (white background, current)', $photoPath, "{$staff->surname}_passport.png", $adminId);

            $this->document($staff, 'APPOINTMENT_LETTER', 'Letter of first appointment - Ogun State Civil Service Commission (' . $firstAppointment->format('d M Y') . ')',
                $this->copySample($publicDisk, 'documents/' . $this->pick(self::APPOINTMENT_LETTERS), "{$folder}/appointment-letter.jpeg"),
                "{$staff->surname}_appointment_letter.jpeg", $adminId);

            $this->document($staff, 'OTHER', 'Archived recruitment passport photograph (as submitted at first appointment)',
                $this->copySample($publicDisk, 'documents/' . $this->pick(self::ARCHIVED_PHOTOS), "{$folder}/recruitment-photo.jpeg"),
                "{$staff->surname}_recruitment_photo.jpeg", $adminId);

            $lastPromotion = $this->seedPromotions($staff, $gradeLevels, $firstAppointment, $adminId);
            if ($lastPromotion) {
                $this->document($staff, 'PROMOTION_LETTER', 'Letter of promotion to ' . $staff->gradeLevel->name . ' (effective ' . $lastPromotion->format('d M Y') . ')',
                    $this->copySample($publicDisk, 'documents/' . $this->pick(self::PROMOTION_LETTERS), "{$folder}/promotion-letter.jpeg"),
                    "{$staff->surname}_promotion_letter.jpeg", $adminId);
            }

            $this->seedRandom($staff, 'profile');
            $staff->fill(array_filter([
                'photo_path' => $photoPath,
                'place_of_birth' => $staff->place_of_birth ?: $this->pick(self::BIRTH_PLACES),
                'residential_address' => $staff->residential_address ?: $this->pick(self::ADDRESSES),
                'personal_email' => $staff->personal_email ?: strtolower($staff->first_name . '.' . $staff->surname) . '@gmail.com',
                'state_of_origin' => $staff->state_of_origin ?: 'Ogun',
                'lga_of_origin' => $staff->lga_of_origin ?: $this->pick(['Abeokuta North', 'Abeokuta South', 'Ijebu Ode', 'Sagamu', 'Ado-Odo/Ota', 'Ikenne']),
                'qualification' => $staff->qualification ?: $this->pick(['B.Sc', 'B.Sc, M.Sc', 'HND', 'B.Tech', 'B.Sc, MBA']),
                'date_of_confirmation' => $staff->date_of_confirmation ?: $firstAppointment->copy()->addYears(2)->toDateString(),
                'date_of_last_promotion' => $lastPromotion?->toDateString() ?? $staff->date_of_last_promotion,
                'date_of_last_deployment' => $staff->date_of_last_deployment ?: Carbon::parse($staff->date_of_present_appointment)->toDateString(),
                'remarks' => $staff->remarks ?: 'Personal file verified during 2026 staff audit.',
            ], fn ($value) => $value !== null))->save();

            $this->seedLeave($staff, $adminId);
            $this->seedTraining($staff, $firstAppointment, $adminId);
            $this->seedRatings($staff, $adminId);
            $this->seedCurrentActivity($staff, $index, $gradeLevels, $lastPromotion === null, $adminId);

            // Every third officer carries a disciplinary record and a confidential query letter.
            if ($index % 3 === 1) {
                $this->seedDisciplinary($staff, $privateDisk, intdiv($index, 3) % count(self::DISCIPLINARY), $adminId);
            }
        }
    }

    /** Re-seeds per section so each value stays stable no matter which fields were already filled. */
    private function seedRandom(Staff $staff, string $section): void
    {
        mt_srand(crc32($staff->staff_number . '|' . $section));
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function copySample($disk, string $sample, string $target): string
    {
        $disk->put($target, $disk->get(self::SAMPLES . '/' . $sample));

        return '/storage/' . $target;
    }

    private function document(Staff $staff, string $type, string $title, string $publicPath, string $fileName, ?int $adminId): void
    {
        $absolute = storage_path('app/public/' . substr($publicPath, strlen('/storage/')));

        StaffDocument::updateOrCreate(
            ['staff_id' => $staff->id, 'document_type' => $type, 'file_path' => $publicPath],
            [
                'title' => $title,
                'file_name' => $fileName,
                'file_size' => File::size($absolute),
                'mime_type' => File::mimeType($absolute),
                'uploaded_by' => $adminId,
            ]
        );
    }

    private function seedPromotions(Staff $staff, $gradeLevels, Carbon $firstAppointment, ?int $adminId): ?Carbon
    {
        $current = $staff->gradeLevel?->level_number;
        if (!$current || $current > 17 || $current < 3) {
            return null;
        }

        $promotionDate = Carbon::parse($staff->date_of_present_appointment);
        if ($promotionDate->lte($firstAppointment)) {
            return null;
        }
        $steps = [[$current - 1, $current, $promotionDate]];
        $earlier = $promotionDate->copy()->subYears(3);
        if ($earlier->gt($firstAppointment)) {
            $steps[] = [$current - 2, $current - 1, $earlier];
        }

        foreach ($steps as [$from, $to, $date]) {
            if (!isset($gradeLevels[$from], $gradeLevels[$to])) {
                continue;
            }
            StaffEvent::updateOrCreate(
                ['staff_id' => $staff->id, 'type' => 'PROMOTION', 'occurred_on' => $date->toDateString()],
                [
                    'title' => "Promotion from {$gradeLevels[$from]->name} to {$gradeLevels[$to]->name}",
                    'start_date' => $date->toDateString(),
                    'from_grade_level_id' => $gradeLevels[$from]->id,
                    'to_grade_level_id' => $gradeLevels[$to]->id,
                    'circular_reference' => sprintf('OG/CSC/PROM/%s/%03d', $date->year, $staff->id),
                    'remarks' => 'Approved by the Civil Service Commission after the annual promotion examination and interview.',
                    'created_by' => $adminId,
                ]
            );
        }

        return $promotionDate;
    }

    private function seedLeave(Staff $staff, ?int $adminId): void
    {
        $this->seedRandom($staff, 'leave');
        $start = Carbon::create(2025, mt_rand(2, 10), mt_rand(1, 20));
        StaffEvent::updateOrCreate(
            ['staff_id' => $staff->id, 'type' => 'LEAVE', 'title' => '2025 Annual Leave'],
            [
                'occurred_on' => $start->toDateString(),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addWeekdays(21)->toDateString(),
                'resumed_on' => $start->copy()->addWeekdays(22)->toDateString(),
                'leave_type' => 'Annual Leave',
                'leave_days' => 21,
                'circular_reference' => sprintf('BIT/ADM/LV/2025/%03d', $staff->id),
                'remarks' => 'Leave approved by the Head of Department; officer resumed as scheduled.',
                'created_by' => $adminId,
            ]
        );
    }

    private function seedTraining(Staff $staff, Carbon $firstAppointment, ?int $adminId): void
    {
        $this->seedRandom($staff, 'training');
        $courses = self::TRAININGS;
        $first = mt_rand(0, count($courses) - 1);
        foreach ([$courses[$first], $courses[($first + 2) % count($courses)]] as $offset => [$title, $type, $provider, $qualification]) {
            $start = Carbon::create(2023 + $offset * 2, mt_rand(1, 11), mt_rand(1, 25));
            if ($start->lt($firstAppointment)) {
                $start = $firstAppointment->copy()->addMonths(6);
            }
            StaffEvent::updateOrCreate(
                ['staff_id' => $staff->id, 'type' => 'TRAINING', 'title' => $title],
                [
                    'occurred_on' => $start->toDateString(),
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->addDays(4)->toDateString(),
                    'training_type' => $type,
                    'provider' => $provider,
                    'qualification_obtained' => $qualification,
                    'remarks' => 'Sponsored by the Bureau as part of the annual capacity-building plan.',
                    'created_by' => $adminId,
                ]
            );
        }
    }

    /** Dates relative to the seeding day so the dashboard's "current" KPIs have sample data. */
    private function seedCurrentActivity(Staff $staff, int $index, $gradeLevels, bool $noPromotionHistory, ?int $adminId): void
    {
        $year = now()->year;
        if ($index % 4 === 0) {
            $start = now()->subDays(5)->startOfDay();
            StaffEvent::updateOrCreate(
                ['staff_id' => $staff->id, 'type' => 'LEAVE', 'title' => "Current Annual Leave ({$year})"],
                [
                    'occurred_on' => $start->toDateString(),
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->addWeekdays(21)->toDateString(),
                    'resumed_on' => null,
                    'leave_type' => 'Annual Leave',
                    'leave_days' => 21,
                    'circular_reference' => sprintf('BIT/ADM/LV/%s/%03d', $year, $staff->id),
                    'remarks' => 'Leave approved by the Head of Department; officer currently on leave.',
                    'created_by' => $adminId,
                ]
            );
        }

        if ($index % 2 === 0) {
            $start = now()->startOfYear()->addMonths(min(now()->month - 1, 1 + $index % 6))->addDays(9);
            StaffEvent::updateOrCreate(
                ['staff_id' => $staff->id, 'type' => 'TRAINING', 'title' => "{$year} Public Service Digital Skills Workshop"],
                [
                    'occurred_on' => $start->toDateString(),
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->addDays(2)->toDateString(),
                    'training_type' => 'Workshop',
                    'provider' => 'Ogun State Bureau of Information Technology',
                    'qualification_obtained' => 'Certificate of Participation',
                    'remarks' => 'In-house capacity-building workshop for the current year.',
                    'created_by' => $adminId,
                ]
            );
        }

        $level = $staff->gradeLevel?->level_number;
        if ($noPromotionHistory && $level && isset($gradeLevels[$level - 1], $gradeLevels[$level])) {
            $date = now()->subMonths(2)->startOfMonth();
            StaffEvent::updateOrCreate(
                ['staff_id' => $staff->id, 'type' => 'PROMOTION', 'circular_reference' => sprintf('OG/CSC/PROM/RECENT/%03d', $staff->id)],
                [
                    'title' => "Promotion from {$gradeLevels[$level - 1]->name} to {$gradeLevels[$level]->name}",
                    'occurred_on' => $date->toDateString(),
                    'start_date' => $date->toDateString(),
                    'from_grade_level_id' => $gradeLevels[$level - 1]->id,
                    'to_grade_level_id' => $gradeLevels[$level]->id,
                    'remarks' => 'Approved by the Civil Service Commission in the most recent promotion exercise.',
                    'created_by' => $adminId,
                ]
            );
            $staff->forceFill(['date_of_last_promotion' => $date->toDateString()])->save();
        }
    }

    private function seedRatings(Staff $staff, ?int $adminId): void
    {
        $this->seedRandom($staff, 'ratings');
        foreach ([2023, 2024, 2025] as $year) {
            $rating = StaffPerformanceRating::firstOrNew(['staff_id' => $staff->id, 'rating_year' => $year]);
            if ($rating->exists) {
                continue;
            }
            $score = mt_rand(30, 50) / 10;
            $rating->fill([
                'rating' => $score,
                'comments' => $score >= 4.5 ? 'Outstanding performance; recommended for accelerated promotion.'
                    : ($score >= 3.5 ? 'Very good performance; meets and often exceeds targets.' : 'Satisfactory performance; requires closer supervision on deliverables.'),
                'entered_by' => $adminId,
            ])->save();
        }
    }

    private function seedDisciplinary(Staff $staff, $privateDisk, int $case, ?int $adminId): void
    {
        $this->seedRandom($staff, 'disciplinary');
        $record = self::DISCIPLINARY[$case];
        $date = Carbon::create(2024, mt_rand(1, 11), mt_rand(1, 25));

        StaffEvent::updateOrCreate(
            ['staff_id' => $staff->id, 'type' => 'DISCIPLINARY', 'title' => $record['title']],
            [
                'occurred_on' => $date->toDateString(),
                'start_date' => $date->toDateString(),
                'disciplinary_action' => $record['action'],
                'reason' => $record['reason'],
                'staff_response' => $record['response'],
                'action_taken' => $record['outcome'],
                'current_state' => $record['state'],
                'circular_reference' => sprintf('BIT/DISC/%s/%03d', $date->year, $staff->id),
                'remarks' => 'Matter closed. Record retained on confidential personal file.',
                'created_by' => $adminId,
            ]
        );

        $path = "confidential/staff/{$staff->id}/query-letter-{$date->year}.jpeg";
        $privateDisk->put($path, Storage::disk('public')->get(self::SAMPLES . '/documents/' . self::PROMOTION_LETTERS[$case]));

        StaffConfidentialDocument::updateOrCreate(
            ['staff_id' => $staff->id, 'storage_path' => $path],
            [
                'title' => $record['title'] . ' - query letter and response',
                'category' => 'DISCIPLINARY',
                'file_name' => "{$staff->surname}_query_letter_{$date->year}.jpeg",
                'file_size' => $privateDisk->size($path),
                'mime_type' => 'image/jpeg',
                'uploaded_by' => $adminId,
            ]
        );
    }
}

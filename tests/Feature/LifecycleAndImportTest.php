<?php

namespace Tests\Feature;

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Models\Staff;
use App\Models\StaffEvent;
use App\Models\User;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\DesignationSeeder;
use Database\Seeders\EmploymentStatusSeeder;
use Database\Seeders\GradeLevelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LifecycleAndImportTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'cache.default' => 'array',
            'session.driver' => 'array',
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        foreach ([RolePermissionSeeder::class, DepartmentSeeder::class, DesignationSeeder::class, GradeLevelSeeder::class, EmploymentStatusSeeder::class] as $seeder) {
            $this->seed($seeder);
        }
        $this->actingAs($this->account('SUPER_ADMIN'));
    }

    private function account(string $role, ?int $department = null): User
    {
        return User::create([
            'name' => $role,
            'username' => strtolower($role),
            'email' => strtolower($role) . '@example.test',
            'password' => bcrypt('test-password'),
            'role_id' => DB::table('roles')->where('code', $role)->value('id'),
            'department_id' => $department,
            'status' => 'active',
        ]);
    }

    private function staff(string $number = 'TEST/001', string $departmentCode = 'BIT-INF'): Staff
    {
        $department = DB::table('departments')->where('code', $departmentCode)->value('id');
        return Staff::create([
            'staff_number' => $number,
            'surname' => 'Test',
            'first_name' => 'Officer',
            'gender' => 'Female',
            'date_of_birth' => '1990-01-01',
            'official_email' => str_replace('/', '-', $number) . '@example.test',
            'department_id' => $department,
            'unit_id' => DB::table('units')->where('department_id', $department)->value('id'),
            'designation_id' => DB::table('designations')->where('name', 'Programme Analyst II')->value('id'),
            'grade_level_id' => DB::table('grade_levels')->where('name', 'GL 08')->value('id'),
            'employment_status_id' => DB::table('employment_statuses')->where('code', 'ACTIVE')->value('id'),
            'date_of_first_appointment' => '2015-01-01',
            'date_of_present_appointment' => '2022-01-01',
            'expected_retirement_date' => '2050-01-01',
        ]);
    }

    public function test_superadmin_creation_promotion_suspension_and_demotion_are_rejected(): void
    {
        $viewer = $this->account('VIEWER');
        $this->postJson('/api/v1/admin/users', [
            'name' => 'Second Admin', 'username' => 'second-admin', 'email' => 'second@example.test',
            'password' => 'test-password', 'role_id' => 1, 'status' => 'active',
        ])->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->patchJson("/api/v1/admin/users/{$viewer->id}", ['role_id' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('role_id');
        $this->patchJson('/api/v1/admin/users/1', ['status' => 'suspended'])->assertForbidden();
        $this->patchJson('/api/v1/admin/users/1', ['role_id' => $viewer->role_id])->assertForbidden();
        $this->assertSame(1, User::where('role_id', 1)->count());
        $this->assertDatabaseHas('users', ['id' => 1, 'status' => 'active', 'role_id' => 1]);
    }

    public function test_database_also_prevents_a_second_superadmin(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('users')->insert([
            'name' => 'Duplicate', 'username' => 'duplicate', 'email' => 'duplicate@example.test',
            'password' => bcrypt('test-password'), 'role_id' => 1, 'status' => 'active',
        ]);
    }

    public function test_migration_restores_archived_staff_as_read_only_and_normalizes_legacy_accounts(): void
    {
        $staff = $this->staff();
        $viewer = $this->account('VIEWER');
        $migration = require base_path('database/migrations/2026_11_23_133630_enforce_account_and_staff_lifecycle.php');
        $migration->down();
        DB::table('staff')->where('id', $staff->id)->update(['deleted_at' => now()]);
        DB::table('users')->where('id', $viewer->id)->update(['status' => 'inactive']);
        $migration->up();
        $this->assertDatabaseHas('staff', ['id' => $staff->id, 'surname' => 'Test', 'is_active' => false, 'deleted_at' => null]);
        $this->assertDatabaseHas('users', ['id' => $viewer->id, 'status' => 'suspended']);
        $this->getJson('/api/v1/staff')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_active', false);
    }

    public function test_regular_account_creation_succeeds_but_inactive_account_status_is_rejected(): void
    {
        $payload = [
            'name' => 'New Officer', 'username' => 'new-officer', 'email' => 'new-officer@example.test',
            'password' => 'test-password', 'role_id' => 3, 'status' => 'active',
        ];
        $response = $this->postJson('/api/v1/admin/users', $payload)->assertCreated()->assertJsonPath('data.is_first_login', true);
        $this->assertDatabaseHas('users', ['id' => $response->json('data.id'), 'status' => 'active', 'role_id' => 3]);
        $payload['username'] = 'another-officer';
        $payload['email'] = 'another@example.test';
        $payload['status'] = 'inactive';
        $this->postJson('/api/v1/admin/users', $payload)->assertUnprocessable()->assertJsonValidationErrors('status');
        $payload['status'] = 'active';
        $payload['role_id'] = 4;
        $this->postJson('/api/v1/admin/users', $payload)->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $payload['department_id'] = DB::table('departments')->where('code', 'BIT-INF')->value('id');
        $this->postJson('/api/v1/admin/users', $payload)->assertCreated();
    }

    public function test_department_admin_cannot_move_staff_out_of_their_department_or_assign_a_foreign_unit(): void
    {
        $staff = $this->staff()->fresh();
        $other = $this->staff('TEST/002', 'BIT-ADM');
        $this->actingAs($this->account('DEPT_ADMIN', $staff->department_id));
        $payload = $staff->toArray();
        $payload['department_id'] = $other->department_id;
        $payload['unit_id'] = $other->unit_id;
        $this->putJson("/api/v1/staff/{$staff->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $payload['department_id'] = $staff->department_id;
        $this->putJson("/api/v1/staff/{$staff->id}", $payload)->assertUnprocessable()->assertJsonValidationErrors('unit_id');
        $this->assertDatabaseHas('staff', ['id' => $staff->id, 'department_id' => $staff->department_id, 'unit_id' => $staff->unit_id]);
    }

    public function test_assigning_department_admin_requires_a_department_and_revokes_existing_sessions(): void
    {
        $viewer = $this->account('VIEWER');
        $viewer->createToken('test');
        $this->patchJson("/api/v1/admin/users/{$viewer->id}", ['role_id' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $department = DB::table('departments')->where('code', 'BIT-INF')->value('id');
        $this->patchJson("/api/v1/admin/users/{$viewer->id}", ['role_id' => 4, 'department_id' => $department])
            ->assertOk()->assertJsonPath('data.department_id', $department);
        $this->assertSame(0, $viewer->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['record_id' => $viewer->id, 'action' => 'USER_UPDATED']);
        $this->patchJson("/api/v1/admin/users/{$viewer->id}", ['department_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->patchJson("/api/v1/admin/users/{$viewer->id}", ['role_id' => 5, 'department_id' => null])->assertOk();
    }

    public function test_suspension_revokes_all_tokens_and_reinstatement_requires_a_new_login(): void
    {
        $viewer = $this->account('VIEWER');
        $token = $viewer->createToken('test')->plainTextToken;
        $this->patchJson("/api/v1/admin/users/{$viewer->id}", ['status' => 'inactive'])->assertUnprocessable();
        $this->patchJson("/api/v1/admin/users/{$viewer->id}", ['status' => 'suspended'])->assertOk();
        $this->assertSame(0, $viewer->tokens()->count());
        $this->getJson('/api/v1/auth/session', ['Authorization' => 'Bearer ' . $token])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['username' => 'viewer', 'password' => 'test-password'])->assertForbidden();
        $this->patchJson("/api/v1/admin/users/{$viewer->id}", ['status' => 'active'])->assertOk();
        $this->getJson('/api/v1/auth/session', ['Authorization' => 'Bearer ' . $token])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['username' => 'viewer', 'password' => 'test-password'])->assertOk();
    }

    public function test_passive_session_checks_do_not_extend_the_idle_timeout(): void
    {
        $viewer = $this->account('VIEWER');
        $token = $viewer->createToken('test');
        $usedAt = now()->subMinutes(30)->startOfSecond();
        $token->accessToken->forceFill(['last_used_at' => $usedAt])->save();
        $this->getJson('/api/v1/auth/session', ['Authorization' => 'Bearer ' . $token->plainTextToken])->assertOk();
        $this->assertSame($usedAt->toDateTimeString(), $token->accessToken->fresh()->last_used_at->toDateTimeString());
        $this->travel(31)->minutes();
        $this->getJson('/api/v1/auth/session', ['Authorization' => 'Bearer ' . $token->plainTextToken])->assertUnauthorized();
    }

    public function test_inactive_staff_remains_visible_and_cannot_be_modified_in_any_folder(): void
    {
        Storage::fake('public');
        $staff = $this->staff();
        $event = $staff->events()->create(['type' => 'TRAINING', 'title' => 'Preserved training', 'occurred_on' => '2025-01-01']);
        $doc = $staff->documents()->create([
            'title' => 'Preserved document', 'document_type' => 'OTHER', 'file_path' => '/storage/kept.txt',
            'file_name' => 'kept.txt', 'mime_type' => 'text/plain', 'file_size' => 4,
        ]);
        Storage::disk('public')->put('kept.txt', 'kept');
        $this->patchJson("/api/v1/staff/{$staff->id}/activity", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson('/api/v1/staff?search=TEST/001')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.is_active', false);
        $this->getJson('/api/v1/staff/nominal-roll')->assertOk()->assertJsonPath('data.0.is_active', false);
        $this->getJson("/api/v1/staff/{$staff->id}")->assertOk();
        $this->postJson("/api/v1/staff/{$staff->id}/profile/print", ['password' => 'test-password'])->assertOk()->assertJsonPath('data.staff.is_active', false);
        $this->putJson("/api/v1/staff/{$staff->id}", ['surname' => 'Changed'])->assertStatus(409);
        $this->postJson("/api/v1/staff/{$staff->id}/postings", [])->assertStatus(409);
        $this->postJson("/api/v1/staff/{$staff->id}/documents", [])->assertStatus(409);
        $this->deleteJson("/api/v1/staff/{$staff->id}/documents/{$doc->id}")->assertStatus(409);
        $this->postJson("/api/v1/staff/{$staff->id}/events", [])->assertStatus(409);
        $this->patchJson("/api/v1/staff-events/{$event->id}", ['title' => 'Changed'])->assertStatus(409);
        $this->deleteJson("/api/v1/staff-events/{$event->id}")->assertStatus(409);
        $this->postJson("/api/v1/staff/{$staff->id}/confidential/ratings", [])->assertStatus(409);
        $this->postJson("/api/v1/staff/{$staff->id}/confidential/documents", [])->assertStatus(409);
        $this->deleteJson("/api/v1/staff/{$staff->id}")->assertStatus(405);
        $this->assertDatabaseHas('staff', ['id' => $staff->id, 'surname' => 'Test', 'deleted_at' => null, 'is_active' => false]);
        $this->assertDatabaseHas('staff_events', ['id' => $event->id, 'title' => 'Preserved training']);
        Storage::disk('public')->assertExists('kept.txt');
        $this->assertDatabaseHas('audit_logs', ['record_id' => $staff->id, 'action' => 'STAFF_SUSPENDED']);
        $this->patchJson("/api/v1/staff/{$staff->id}/activity", ['is_active' => true])->assertOk();
        $this->patchJson("/api/v1/staff-events/{$event->id}", ['title' => 'Updated training'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['record_id' => $staff->id, 'action' => 'STAFF_REACTIVATED']);
    }

    public function test_only_superadmin_and_hradmin_can_change_staff_activity(): void
    {
        $staff = $this->staff();
        foreach (['HR_OFFICER', 'DEPT_ADMIN', 'VIEWER'] as $role) {
            $this->actingAs($this->account($role, $staff->department_id));
            $this->patchJson("/api/v1/staff/{$staff->id}/activity", ['is_active' => false])->assertForbidden();
        }
        $this->assertTrue($staff->fresh()->is_active);
        $this->actingAs($this->account('HR_ADMIN'));
        $this->patchJson("/api/v1/staff/{$staff->id}/activity", ['is_active' => false])->assertOk();
        $this->patchJson("/api/v1/staff/{$staff->id}/activity", ['is_active' => true])->assertOk();
    }

    public function test_milestone_alerts_only_include_staff_due_for_confirmation(): void
    {
        $this->travelTo('2026-11-27');
        $due = $this->staff('DUE/001');
        $recent = $this->staff('RECENT/001');
        $recent->forceFill([
            'date_of_first_appointment' => '2025-01-01',
            'expected_retirement_date' => '2050-01-01',
        ])->save();
        $confirmed = $this->staff('CONF/001');
        $confirmed->forceFill(['date_of_confirmation' => '2017-01-01'])->save();

        $this->getJson('/api/v1/alerts/milestones')->assertOk()
            ->assertJsonPath('data.counts.confirmation_due', 1)
            ->assertJsonPath('data.confirmation_due.0.id', $due->id)
            ->assertJsonPath('data.confirmation_due.0.confirmation_due', true);
    }

    public function test_permanent_secretary_is_available_as_a_staff_designation(): void
    {
        $this->getJson('/api/v1/staff/lookups')->assertOk()
            ->assertJsonFragment([
                'name' => 'Permanent Secretary',
                'code' => 'PERM-SEC',
                'cadre_name' => 'Executive Leadership',
            ]);
    }

    public function test_confirmation_dates_must_be_at_least_two_years_after_dofa_and_before_retirement(): void
    {
        $staff = $this->staff();
        $payload = $staff->toArray();
        $payload['step'] = 1;
        $payload['date_of_confirmation'] = '2016-12-31';

        $this->putJson("/api/v1/staff/{$staff->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_confirmation');

        $payload['date_of_confirmation'] = '2050-01-02';
        $this->putJson("/api/v1/staff/{$staff->id}", $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_confirmation');

        $payload['date_of_confirmation'] = '2017-01-01';
        $this->putJson("/api/v1/staff/{$staff->id}", $payload)->assertOk();
        $this->assertDatabaseHas('staff', ['id' => $staff->id, 'date_of_confirmation' => '2017-01-01']);
    }

    public function test_retired_staff_is_read_only_and_overdue_alerts_show_the_retirement_trigger(): void
    {
        $serviceRetired = $this->staff('RET/001');
        $serviceRetired->forceFill([
            'date_of_birth' => '1970-01-01',
            'date_of_first_appointment' => '1990-01-01',
            'expected_retirement_date' => '2025-01-01',
        ])->save();
        $ageRetired = $this->staff('RET/002');
        $ageRetired->forceFill([
            'date_of_birth' => '1960-01-01',
            'date_of_first_appointment' => '2000-01-01',
            'expected_retirement_date' => '2020-01-01',
        ])->save();

        $this->getJson("/api/v1/staff/{$serviceRetired->id}")->assertOk()
            ->assertJsonPath('data.record_status', 'retired')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.retirement_reason', '35 years of service');
        $this->getJson('/api/v1/alerts/milestones')->assertOk()
            ->assertJsonPath('data.counts.overdue', 2)
            ->assertJsonPath('data.overdue.0.retirement_reason', 'Age 60')
            ->assertJsonPath('data.overdue.1.retirement_reason', '35 years of service');
        $this->putJson("/api/v1/staff/{$serviceRetired->id}", ['surname' => 'Changed'])->assertStatus(409);
        $this->patchJson("/api/v1/staff/{$serviceRetired->id}/activity", ['is_active' => true])->assertStatus(409);
    }

    public function test_file_uploads_over_two_megabytes_are_rejected_except_csv_imports(): void
    {
        Storage::fake('public');
        $staff = $this->staff();
        $oversizedDocument = UploadedFile::fake()->create('oversized.pdf', 2049, 'application/pdf');
        $this->postJson("/api/v1/staff/{$staff->id}/documents", [
            'title' => 'Oversized document',
            'document_type' => 'OTHER',
            'file' => $oversizedDocument,
        ])->assertUnprocessable()->assertJsonPath('errors.file.0', 'The uploaded file must not exceed 2 MB.');

        $oversizedConfidential = UploadedFile::fake()->create('oversized.pdf', 2049, 'application/pdf');
        $this->postJson("/api/v1/staff/{$staff->id}/confidential/documents", [
            'password' => 'test-password',
            'title' => 'Oversized confidential document',
            'category' => 'PERSONNEL',
            'file' => $oversizedConfidential,
        ])->assertUnprocessable()->assertJsonPath('errors.file.0', 'The uploaded file must not exceed 2 MB.');

        $oversizedPromotion = UploadedFile::fake()->create('oversized.pdf', 2049, 'application/pdf');
        $this->postJson("/api/v1/staff/{$staff->id}/events", [
            'type' => 'PROMOTION',
            'title' => 'Promotion with oversized attachment',
            'occurred_on' => '2026-01-01',
            'from_grade_level_id' => $staff->grade_level_id,
            'to_grade_level_id' => DB::table('grade_levels')->where('name', 'GL 10')->value('id'),
            'file' => $oversizedPromotion,
        ])->assertUnprocessable()->assertJsonPath('errors.file.0', 'The uploaded file must not exceed 2 MB.');

        $handle = fopen(base_path('BIT_Nominal_Roll_Template.csv'), 'r');
        $headers = fgetcsv($handle);
        $row = fgetcsv($handle);
        fclose($handle);
        $row[array_search('remarks', $headers, true)] = str_repeat('x', 2 * 1024 * 1024);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, $headers);
        fputcsv($stream, $row);
        rewind($stream);
        $largeCsvContents = stream_get_contents($stream);
        fclose($stream);
        $largeCsv = UploadedFile::fake()->createWithContent('large.csv', $largeCsvContents);

        $this->postJson('/api/v1/staff/import', ['file' => $largeCsv])->assertOk()
            ->assertJsonPath('failed_count', 1);
    }

    public function test_promotion_records_accept_an_optional_attachment(): void
    {
        Storage::fake('public');
        $staff = $this->staff();
        $fromGrade = $staff->grade_level_id;
        $toGrade = DB::table('grade_levels')->where('name', 'GL 10')->value('id');
        $this->postJson("/api/v1/staff/{$staff->id}/events", [
            'type' => 'PROMOTION',
            'title' => 'Promotion without attachment',
            'occurred_on' => '2025-01-01',
            'from_grade_level_id' => $fromGrade,
            'to_grade_level_id' => $toGrade,
        ])->assertCreated()->assertJsonPath('data.attachment_path', null);

        $nextGrade = DB::table('grade_levels')->where('name', 'GL 12')->value('id');
        $file = UploadedFile::fake()->create('promotion.pdf', 10, 'application/pdf');
        $secondRecord = $this->postJson("/api/v1/staff/{$staff->id}/events", [
            'type' => 'PROMOTION',
            'title' => 'Promotion with attachment',
            'occurred_on' => '2026-01-01',
            'from_grade_level_id' => $toGrade,
            'to_grade_level_id' => $nextGrade,
            'file' => $file,
        ])->assertCreated()->assertJsonPath('data.attachment_name', 'promotion.pdf');

        $path = str_replace('/storage/', '', $secondRecord->json('data.attachment_path'));
        Storage::disk('public')->assertExists($path);
        $this->assertSame(2, StaffEvent::where('staff_id', $staff->id)->count());
    }

    public function test_hr_officer_and_department_admin_can_read_disciplinary_records_with_department_isolation(): void
    {
        $own = $this->staff();
        $other = $this->staff('TEST/002', 'BIT-ADM');
        foreach ([$own, $other] as $staff) {
            $staff->events()->create(['type' => 'DISCIPLINARY', 'title' => 'Written warning', 'occurred_on' => '2025-01-01']);
        }
        $this->actingAs($this->account('HR_OFFICER'));
        $this->getJson('/api/v1/staff-events?type=DISCIPLINARY')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson("/api/v1/staff/{$own->id}/events?type=DISCIPLINARY")->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/staff/{$own->id}/profile/print")->assertOk()->assertJsonPath('data.events.0.type', 'DISCIPLINARY');

        $this->actingAs($this->account('DEPT_ADMIN', $own->department_id));
        $this->getJson('/api/v1/staff')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/staff?department_id=' . $other->department_id)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/staff-events?type=DISCIPLINARY')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson("/api/v1/staff/{$own->id}/events?type=DISCIPLINARY")->assertOk();
        $this->getJson("/api/v1/staff/{$other->id}")->assertNotFound();
        $this->getJson("/api/v1/staff/{$other->id}/documents")->assertNotFound();
        $this->postJson("/api/v1/staff/{$other->id}/profile/print")->assertNotFound();
        $this->getJson('/api/v1/staff/export')->assertOk()->assertDontSee('TEST/002');
    }

    public function test_dashboard_reports_workforce_kpis_within_department_scope(): void
    {
        $onLeave = $this->staff('KPI/001');
        $promoted = $this->staff('KPI/002');
        $inactive = $this->staff('KPI/003');
        $other = $this->staff('KPI/004', 'BIT-SRP');
        $retired = $this->staff('KPI/005');
        $other->update(['gender' => 'Male']);
        $inactive->forceFill(['is_active' => false])->save();
        $retired->forceFill(['expected_retirement_date' => now()->subDay()->toDateString()])->save();
        StaffEvent::create(['staff_id' => $onLeave->id, 'type' => 'LEAVE', 'title' => 'Annual leave', 'occurred_on' => now()->subDays(2), 'start_date' => now()->subDays(2), 'end_date' => now()->addDays(5), 'leave_type' => 'Annual Leave']);
        StaffEvent::create(['staff_id' => $promoted->id, 'type' => 'LEAVE', 'title' => 'Finished leave', 'occurred_on' => now()->subMonth(), 'start_date' => now()->subMonth(), 'end_date' => now()->addDays(3), 'resumed_on' => now()->subDays(10), 'leave_type' => 'Annual Leave']);
        StaffEvent::create(['staff_id' => $promoted->id, 'type' => 'PROMOTION', 'title' => 'Promotion', 'occurred_on' => now()->subMonths(2)]);
        StaffEvent::create(['staff_id' => $other->id, 'type' => 'PROMOTION', 'title' => 'Old promotion', 'occurred_on' => now()->subYears(2)]);
        StaffEvent::create(['staff_id' => $other->id, 'type' => 'DISCIPLINARY', 'title' => 'Query', 'occurred_on' => now()->subMonth()]);

        $this->getJson('/api/v1/dashboard/statistics')->assertOk()
            ->assertJsonPath('data.summary.active_staff', 5)
            ->assertJsonPath('data.summary.active_records', 3)
            ->assertJsonPath('data.summary.suspended_records', 1)
            ->assertJsonPath('data.summary.retired_records', 1)
            ->assertJsonPath('data.summary.inactive_records', 2)
            ->assertJsonPath('data.summary.on_leave_now', 1)
            ->assertJsonPath('data.summary.promoted_last_6m', 1)
            ->assertJsonPath('data.summary.disciplinary_last_12m', 1)
            ->assertJsonPath('data.by_gender.Female', 4)
            ->assertJsonPath('data.by_gender.Male', 1)
            ->assertJsonPath('data.by_status.ACTIVE', 5)
            ->assertJsonPath('data.by_grade_band.GL 07-12', 5);

        $this->actingAs($this->account('DEPT_ADMIN', DB::table('departments')->where('code', 'BIT-SRP')->value('id')));
        $this->getJson('/api/v1/dashboard/statistics')->assertOk()
            ->assertJsonPath('data.summary.active_staff', 1)
            ->assertJsonPath('data.summary.on_leave_now', 0)
            ->assertJsonPath('data.summary.disciplinary_last_12m', 1)
            ->assertJsonPath('data.by_gender.Male', 1);

        $this->actingAs($this->account('VIEWER'));
        $this->getJson('/api/v1/dashboard/statistics')->assertOk()
            ->assertJsonPath('data.summary.disciplinary_last_12m', null);
    }

    public function test_disciplinary_history_is_read_only_for_hr_officers_and_department_admins(): void
    {
        $staff = $this->staff();
        $event = $staff->events()->create([
            'type' => 'DISCIPLINARY', 'title' => 'Preserved warning', 'occurred_on' => '2025-01-01',
        ]);
        foreach (['HR_OFFICER', 'DEPT_ADMIN', 'VIEWER'] as $role) {
            $this->actingAs($this->account($role, $staff->department_id));
            $this->postJson("/api/v1/staff/{$staff->id}/events", [
                'type' => 'DISCIPLINARY', 'title' => 'New warning', 'occurred_on' => '2025-02-01',
            ])->assertForbidden();
            $this->patchJson("/api/v1/staff-events/{$event->id}", ['title' => 'Changed warning'])->assertForbidden();
            $this->deleteJson("/api/v1/staff-events/{$event->id}")->assertForbidden();
        }
        $this->assertDatabaseHas('staff_events', ['id' => $event->id, 'title' => 'Preserved warning']);
        $this->assertSame(1, StaffEvent::count());
        $this->getJson("/api/v1/staff/{$staff->id}/events?type=DISCIPLINARY")->assertForbidden();

        $this->actingAs($this->account('HR_ADMIN'));
        $this->patchJson("/api/v1/staff-events/{$event->id}", ['title' => 'Reviewed warning'])->assertOk();
        $this->assertDatabaseHas('staff_events', ['id' => $event->id, 'title' => 'Reviewed warning']);
    }

    public function test_downloaded_template_and_static_template_share_an_importable_schema(): void
    {
        $response = $this->get('/api/v1/staff/template')->assertOk();
        $contents = ltrim($response->streamedContent(), "\xEF\xBB\xBF");
        $parse = fn ($csv) => array_map('str_getcsv', preg_split('/\r?\n/', trim($csv)));
        $this->assertSame($parse(file_get_contents(base_path('BIT_Nominal_Roll_Template.csv'))), $parse($contents));
        $file = UploadedFile::fake()->createWithContent('template.csv', $contents);
        $this->postJson('/api/v1/staff/import', ['file' => $file])->assertOk()->assertJsonPath('imported_count', 1)->assertJsonPath('failed_count', 0);
        $this->assertDatabaseHas('staff', [
            'staff_number' => 'BIT/IMPORT/0001', 'place_of_birth' => 'Abeokuta', 'state_of_origin' => 'Ogun',
            'phone_number' => '08020000001', 'date_of_confirmation' => '2020-09-01', 'is_active' => true,
        ]);
    }

    public function test_all_100_sample_csv_records_import_without_errors_and_preserve_optional_fields(): void
    {
        $file = UploadedFile::fake()->createWithContent('sample.csv', file_get_contents(base_path('BIT_Nominal_Roll_Sample_Data_100.csv')));
        $this->postJson('/api/v1/staff/import', ['file' => $file])->assertOk()
            ->assertJsonPath('success', true)->assertJsonPath('imported_count', 100)->assertJsonPath('failed_count', 0);
        $this->assertSame(100, Staff::count());
        $this->assertDatabaseHas('staff', [
            'staff_number' => 'BIT/EMP/0011', 'ippis_number' => 'IPPIS-SAMPLE-001', 'phone_number' => '08020000001',
            'residential_address' => '1 Sample Estate, Abeokuta', 'state_of_origin' => 'Ogun',
        ]);
    }

    public function test_import_rejects_bad_dates_status_units_and_duplicate_og_numbers_without_losing_valid_rows(): void
    {
        $handle = fopen(base_path('BIT_Nominal_Roll_Template.csv'), 'r');
        $row = array_combine(fgetcsv($handle), fgetcsv($handle));
        fclose($handle);
        $bad = $row;
        $bad['staff_number'] = 'BAD/001';
        $bad['official_email'] = 'bad@example.test';
        $bad['date_of_birth'] = 'not-a-date';
        $bad['status'] = 'NOT_A_STATUS';
        $bad['department_code'] = 'BIT-ADM';
        $duplicate = $row;
        $duplicate['staff_number'] = 'DUP/001';
        $duplicate['official_email'] = 'dup@example.test';
        $result = $this->postJson('/api/v1/staff/import', ['rows' => [$row, $bad, $duplicate]])->assertOk()
            ->assertJsonPath('imported_count', 1)->assertJsonPath('failed_count', 2);
        $this->assertStringContainsString('Unknown employment status', implode(' ', $result->json('errors.0.errors')));
        $this->assertStringContainsString('does not belong', implode(' ', $result->json('errors.0.errors')));
        $this->assertStringContainsString('OG Number', implode(' ', $result->json('errors.1.errors')));
        $malformed = UploadedFile::fake()->createWithContent('bad.csv', implode(',', array_keys($row)) . "\nshort,row\n");
        $this->postJson('/api/v1/staff/import', ['file' => $malformed])->assertUnprocessable();
        $this->assertSame(1, Staff::count());
    }

    public function test_only_the_superadmin_can_manage_system_configuration(): void
    {
        $this->actingAs($this->account('HR_ADMIN'));

        $this->getJson('/api/v1/admin/configuration')->assertForbidden();
        $this->postJson('/api/v1/admin/configuration/designations', ['name' => 'Chief Analyst', 'code' => 'CA'])->assertForbidden();
        $this->assertDatabaseMissing('designations', ['code' => 'CA']);
    }

    public function test_configuration_values_in_use_can_be_deactivated_but_not_deleted_or_renamed(): void
    {
        $staff = $this->staff();
        $staff->forceFill(['lga_of_origin' => 'Abeokuta South'])->save();
        $designationId = $staff->designation_id;
        $lga = DB::table('lookup_options')->where(['category' => 'lga', 'value' => 'Abeokuta South'])->value('id');

        $this->deleteJson("/api/v1/admin/configuration/designations/{$designationId}")->assertStatus(409);
        $this->patchJson("/api/v1/admin/configuration/lga/{$lga}", ['value' => 'Abeokuta S.'])
            ->assertUnprocessable()->assertJsonValidationErrors('value');
        $this->patchJson("/api/v1/admin/configuration/designations/{$designationId}", ['is_active' => false])->assertOk();

        $this->assertDatabaseHas('designations', ['id' => $designationId, 'is_active' => false]);
        $this->assertDatabaseHas('lookup_options', ['id' => $lga, 'value' => 'Abeokuta South']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'CONFIG_UPDATED', 'record_id' => $designationId]);
        $this->getJson('/api/v1/staff/lookups')->assertOk()
            ->assertJsonMissing(['id' => $designationId, 'name' => 'Programme Analyst II'])
            ->assertJsonPath('data.options.lga.0', 'Abeokuta North');
    }

    public function test_unused_configuration_values_can_be_added_and_removed_but_reserved_statuses_are_protected(): void
    {
        $created = $this->postJson('/api/v1/admin/configuration/designations', ['name' => 'Chief Analyst', 'code' => 'ca-1'])
            ->assertCreated();
        $designationId = DB::table('designations')->where('code', 'CA-1')->value('id');
        $this->assertNotNull($designationId, $created->json('message'));
        $this->postJson('/api/v1/admin/configuration/designations', ['name' => 'Chief Analyst', 'code' => 'CA-2'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->deleteJson("/api/v1/admin/configuration/designations/{$designationId}")->assertOk();
        $this->assertDatabaseMissing('designations', ['id' => $designationId]);

        $active = DB::table('employment_statuses')->where('code', 'ACTIVE')->value('id');
        $this->deleteJson("/api/v1/admin/configuration/employment_statuses/{$active}")->assertStatus(409);
        $this->patchJson("/api/v1/admin/configuration/employment_statuses/{$active}", ['is_active' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_inactive_designations_cannot_be_newly_assigned_but_existing_holders_keep_them(): void
    {
        $staff = $this->staff();
        DB::table('designations')->where('id', $staff->designation_id)->update(['is_active' => false]);

        $payload = $staff->toArray();
        $payload['step'] = 1;
        $payload['surname'] = 'Renamed';
        $this->putJson("/api/v1/staff/{$staff->id}", $payload)->assertOk();

        $payload['staff_number'] = 'TEST/002';
        $payload['official_email'] = 'second@example.test';
        $this->postJson('/api/v1/staff', $payload)->assertUnprocessable()->assertJsonValidationErrors('designation_id');
    }

    public function test_suspension_audit_entry_describes_the_staff_member_and_reason(): void
    {
        $staff = $this->staff();

        $this->patchJson("/api/v1/staff/{$staff->id}/activity", ['is_active' => false, 'reason' => 'Pending disciplinary review'])->assertOk();

        $description = DB::table('audit_logs')->where('action', 'STAFF_SUSPENDED')->value('description');
        $this->assertStringContainsString('TEST/001', $description);
        $this->assertStringContainsString('Reason: Pending disciplinary review', $description);
    }

    public function test_automatic_retirement_is_logged_once_as_a_system_action(): void
    {
        $staff = $this->staff('RET/010');
        $staff->forceFill(['date_of_birth' => '1960-01-01', 'expected_retirement_date' => '2020-01-01'])->save();

        $this->getJson('/api/v1/alerts/milestones')->assertOk();
        $this->artisan('hrms:record-retirements')->assertExitCode(0);

        $entries = DB::table('audit_logs')->where(['action' => 'STAFF_RETIRED', 'record_id' => $staff->id])->get();
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]->user_id);
        $this->assertStringContainsString('attaining the statutory age of 60 years', $entries[0]->description);
    }
}

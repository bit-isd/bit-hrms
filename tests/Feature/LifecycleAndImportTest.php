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
        $this->assertDatabaseHas('audit_logs', ['record_id' => $staff->id, 'action' => 'STAFF_DEACTIVATED']);
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
        $other->update(['gender' => 'Male']);
        $inactive->forceFill(['is_active' => false])->save();
        StaffEvent::create(['staff_id' => $onLeave->id, 'type' => 'LEAVE', 'title' => 'Annual leave', 'occurred_on' => now()->subDays(2), 'start_date' => now()->subDays(2), 'end_date' => now()->addDays(5), 'leave_type' => 'Annual Leave']);
        StaffEvent::create(['staff_id' => $promoted->id, 'type' => 'LEAVE', 'title' => 'Finished leave', 'occurred_on' => now()->subMonth(), 'start_date' => now()->subMonth(), 'end_date' => now()->addDays(3), 'resumed_on' => now()->subDays(10), 'leave_type' => 'Annual Leave']);
        StaffEvent::create(['staff_id' => $promoted->id, 'type' => 'PROMOTION', 'title' => 'Promotion', 'occurred_on' => now()->subMonths(2)]);
        StaffEvent::create(['staff_id' => $other->id, 'type' => 'PROMOTION', 'title' => 'Old promotion', 'occurred_on' => now()->subYears(2)]);
        StaffEvent::create(['staff_id' => $other->id, 'type' => 'DISCIPLINARY', 'title' => 'Query', 'occurred_on' => now()->subMonth()]);

        $this->getJson('/api/v1/dashboard/statistics')->assertOk()
            ->assertJsonPath('data.summary.active_staff', 4)
            ->assertJsonPath('data.summary.active_records', 3)
            ->assertJsonPath('data.summary.inactive_records', 1)
            ->assertJsonPath('data.summary.on_leave_now', 1)
            ->assertJsonPath('data.summary.promoted_last_6m', 1)
            ->assertJsonPath('data.summary.disciplinary_last_12m', 1)
            ->assertJsonPath('data.by_gender.Female', 3)
            ->assertJsonPath('data.by_gender.Male', 1)
            ->assertJsonPath('data.by_status.ACTIVE', 4)
            ->assertJsonPath('data.by_grade_band.GL 07-12', 4);

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
}

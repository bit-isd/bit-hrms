<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $superRoleId = (int) (DB::table('roles')->where('code', 'SUPER_ADMIN')->value('id') ?? 1);
        if (DB::table('users')->where('role_id', $superRoleId)->count() > 1) {
            throw new RuntimeException('Multiple super administrator accounts exist. Resolve them before running this migration.');
        }
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
        DB::table('staff')->whereNotNull('deleted_at')->update(['is_active' => false, 'deleted_at' => null]);
        DB::table('users')->where('status', 'inactive')->update(['status' => 'suspended']);
        $this->accountStatuses(['active', 'suspended']);
        Schema::table('users', function (Blueprint $table) use ($superRoleId) {
            $guard = $table->integer('super_admin_guard')->nullable();
            $expression = "CASE WHEN role_id = {$superRoleId} THEN 1 ELSE NULL END";
            if (DB::getDriverName() === 'sqlite') {
                $guard->virtualAs($expression);
            } else {
                $guard->storedAs($expression);
            }
            $guard->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['super_admin_guard']);
            $table->dropColumn('super_admin_guard');
        });
        $this->accountStatuses(['active', 'suspended', 'inactive']);
        Schema::table('staff', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }

    private function accountStatuses(array $statuses): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
            $values = implode(', ', array_map(fn ($value) => "'" . $value . "'", $statuses));
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ({$values}))");
        } else {
            Schema::table('users', fn (Blueprint $table) => $table->enum('status', $statuses)->default('active')->change());
        }
    }
};

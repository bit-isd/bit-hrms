<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('designations')->updateOrInsert(
            ['code' => 'PERM-SEC'],
            [
                'name' => 'Permanent Secretary',
                'cadre_name' => 'Executive Leadership',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        $designationId = DB::table('designations')->where('code', 'PERM-SEC')->value('id');
        if ($designationId && !DB::table('staff')->where('designation_id', $designationId)->exists()) {
            DB::table('designations')->where('id', $designationId)->delete();
        }
    }
};

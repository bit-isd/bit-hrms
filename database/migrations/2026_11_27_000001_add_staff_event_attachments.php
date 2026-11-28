<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_events', function (Blueprint $table) {
            $table->string('attachment_path', 500)->nullable();
            $table->string('attachment_name', 255)->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->string('attachment_mime_type', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff_events', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name', 'attachment_size', 'attachment_mime_type']);
        });
    }
};

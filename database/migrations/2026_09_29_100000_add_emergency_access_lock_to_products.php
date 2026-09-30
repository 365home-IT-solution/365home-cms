<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('emergency_locked_at')->nullable()->after('unlock_both_locks');
            $table->string('emergency_locked_by', 36)->nullable()->after('emergency_locked_at');
            $table->text('emergency_lock_reason')->nullable()->after('emergency_locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['emergency_locked_at', 'emergency_locked_by', 'emergency_lock_reason']);
        });
    }
};

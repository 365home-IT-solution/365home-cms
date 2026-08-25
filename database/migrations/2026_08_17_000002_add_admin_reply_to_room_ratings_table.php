<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_ratings', function (Blueprint $table) {
            $table->text('admin_reply')->nullable()->after('comment');
            $table->char('replied_by', 36)->nullable()->after('admin_reply');
            $table->timestamp('replied_at')->nullable()->after('replied_by');

            $table->foreign('replied_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('room_ratings', function (Blueprint $table) {
            $table->dropForeign(['replied_by']);
            $table->dropColumn(['admin_reply', 'replied_by', 'replied_at']);
        });
    }
};

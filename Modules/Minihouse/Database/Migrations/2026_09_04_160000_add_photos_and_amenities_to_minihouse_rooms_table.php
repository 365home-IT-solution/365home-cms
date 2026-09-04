<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_rooms', function (Blueprint $table) {
            $table->json('photos')->nullable()->after('note');
            $table->json('amenities')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_rooms', function (Blueprint $table) {
            $table->dropColumn(['photos', 'amenities']);
        });
    }
};

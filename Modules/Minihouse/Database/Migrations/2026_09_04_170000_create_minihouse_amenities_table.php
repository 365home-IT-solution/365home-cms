<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('minihouse_room_amenity', function (Blueprint $table) {
            $table->foreignId('room_id')->constrained('minihouse_rooms')->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained('minihouse_amenities')->cascadeOnDelete();
            $table->primary(['room_id', 'amenity_id']);
        });

        // Thay bằng quan hệ many-to-many CRUD được (bảng minihouse_amenities) — cột JSON cứng danh
        // sách cố định không sửa/thêm được ngoài code, không đáp ứng yêu cầu quản lý tiện ích linh hoạt.
        Schema::table('minihouse_rooms', function (Blueprint $table) {
            $table->dropColumn('amenities');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_rooms', function (Blueprint $table) {
            $table->json('amenities')->nullable()->after('note');
        });

        Schema::dropIfExists('minihouse_room_amenity');
        Schema::dropIfExists('minihouse_amenities');
    }
};

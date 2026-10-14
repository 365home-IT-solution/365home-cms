<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // "Bảng giá mẫu" — bộ khung giờ + giá có sẵn để đối tác chọn rồi áp xuống phòng (copy vào
    // room_time_slots, sau đó phòng sửa giá độc lập). partner_id NULL = mẫu hệ thống dùng chung;
    // đối tác sửa mẫu hệ thống thì tự tách thành bản riêng (source_template_id trỏ về mẫu gốc).
    // KHÁC price_boards (bảng giá THEO LỊCH, ghi đè giá phòng trong 1 khoảng ngày rồi khôi phục).
    public function up(): void
    {
        if (! Schema::hasTable('price_templates')) {
            Schema::create('price_templates', function (Blueprint $table) {
                $table->id();
                $table->uuid('partner_id')->nullable();
                $table->foreignId('source_template_id')->nullable()->constrained('price_templates')->nullOnDelete();
                $table->string('name');
                $table->text('note')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
                $table->index(['partner_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('price_template_slots')) {
            Schema::create('price_template_slots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_template_id')->constrained('price_templates')->cascadeOnDelete();
                $table->foreignId('timeslot_id')->constrained('time_slots')->restrictOnDelete();
                $table->unsignedBigInteger('price')->nullable();
                $table->string('checkin', 5)->nullable();
                $table->string('checkout', 5)->nullable();
                $table->boolean('over_night')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['price_template_id', 'timeslot_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('price_template_slots');
        Schema::dropIfExists('price_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Ghi lại TỪNG bản ghi do "Tạo dữ liệu mẫu" sinh ra (App\Services\PartnerSampleDataGenerator) để
    // "Xoá dữ liệu mẫu" xoá đúng những gì đã tạo, không đụng dữ liệu thật của đối tác.
    // record_type: time_slot | price_template | tag | service | room. record_id là string vì phòng
    // dùng ULID, các loại còn lại id số.
    public function up(): void
    {
        if (Schema::hasTable('sample_data_records')) {
            return;
        }

        Schema::create('sample_data_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id');
            $table->uuid('batch_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('record_type', 32);
            $table->string('record_id', 64);
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
            $table->index(['partner_id', 'record_type']);
            $table->unique(['record_type', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_data_records');
    }
};

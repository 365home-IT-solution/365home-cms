<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('room_additional_service_assigns')) {
            return;
        }

        Schema::create('room_additional_service_assigns', function (Blueprint $table) {
            $table->id();
            $table->char('room_id', 26)->comment('ULID của phòng (products.id)');
            $table->unsignedBigInteger('additional_service_id')->comment('ID dịch vụ bổ sung');

            $table->unique(['room_id', 'additional_service_id'], 'room_service_unique');

            $table->foreign('room_id', 'room_svc_assign_room_id_fk')
                ->references('id')
                ->on('products')
                ->onDelete('cascade');

            $table->foreign('additional_service_id', 'room_svc_assign_service_id_fk')
                ->references('id')
                ->on('additional_services')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_additional_service_assigns');
    }
};
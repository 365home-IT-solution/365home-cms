<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_reminders', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->date('remind_date');
            $table->string('type')->default('khac'); // thu_tien | het_han_hop_dong | bao_tri | khac
            $table->foreignId('room_id')->nullable()->constrained('minihouse_rooms')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('minihouse_contracts')->nullOnDelete();
            $table->boolean('is_done')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_reminders');
    }
};

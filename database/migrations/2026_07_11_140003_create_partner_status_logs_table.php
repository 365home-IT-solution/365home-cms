<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partner_status_logs')) {
            return;
        }

        Schema::create('partner_status_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id');
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();

            $table->char('changed_by', 36)->nullable();
            $table->foreign('changed_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();

            $table->index('partner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_status_logs');
    }
};

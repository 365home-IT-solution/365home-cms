<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('partner_id')->nullable();
            $table->string('code')->unique();
            $table->dateTime('checked_at');
            $table->text('note')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_checks');
    }
};

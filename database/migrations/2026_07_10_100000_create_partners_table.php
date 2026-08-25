<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partners')) {
            return;
        }

        Schema::create('partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('tax_code')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->boolean('status')->default(true);
            // Super_admin nào tạo đối tác này — dùng cho audit, không dùng để lọc quyền.
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partners');
    }
};

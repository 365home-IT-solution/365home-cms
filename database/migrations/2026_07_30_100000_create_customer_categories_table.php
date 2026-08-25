<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_categories', function (Blueprint $table) {
            $table->id();
            $table->string('customer_id', 36);
            $table->unsignedBigInteger('category_id');
            $table->timestamps();

            $table->unique(['customer_id', 'category_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('categories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_categories');
    }
};

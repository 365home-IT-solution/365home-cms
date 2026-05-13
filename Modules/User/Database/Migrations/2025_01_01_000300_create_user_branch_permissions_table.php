<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_branch_permissions')) {
            return;
        }

        Schema::create('user_branch_permissions', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 36);
            $table->unsignedBigInteger('category_id');
            $table->timestamps();

            $table->index('user_id');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branch_permissions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_data_scopes')) {
            return;
        }

        Schema::create('user_data_scopes', function (Blueprint $table) {
            $table->id();
            $table->char('user_id', 36);
            $table->string('table_name');
            $table->boolean('can_view')->default(true);
            $table->boolean('scope_own_data')->default(false);
            $table->string('owner_column')->default('user_id');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_data_scopes');
    }
};

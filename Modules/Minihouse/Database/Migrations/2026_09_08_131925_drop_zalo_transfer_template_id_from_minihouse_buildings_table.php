<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn('zalo_transfer_template_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->string('zalo_transfer_template_id')->nullable()->after('owner_bank_account_holder');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->string('id_card_front')->nullable()->after('id_card_number');
            $table->string('id_card_back')->nullable()->after('id_card_front');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_tenants', function (Blueprint $table) {
            $table->dropColumn(['id_card_front', 'id_card_back']);
        });
    }
};

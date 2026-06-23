<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('province_branches', function (Blueprint $table) {
            $table->unsignedInteger('ward_code')->nullable()->index()->after('categorie_id');
        });
    }

    public function down(): void
    {
        Schema::table('province_branches', function (Blueprint $table) {
            $table->dropIndex(['ward_code']);
            $table->dropColumn('ward_code');
        });
    }
};

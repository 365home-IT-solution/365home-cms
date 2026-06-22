<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provinces', function (Blueprint $table) {
            $table->unsignedInteger('code')->nullable()->unique()->after('id');
            $table->string('division_type')->nullable()->after('slug');
            $table->string('codename')->nullable()->unique()->after('division_type');
            $table->unsignedSmallInteger('phone_code')->nullable()->after('codename');
        });
    }

    public function down(): void
    {
        Schema::table('provinces', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropUnique(['codename']);
            $table->dropColumn(['code', 'division_type', 'codename', 'phone_code']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hợp đồng hợp tác (mẫu 365 HOME) cần: chức vụ người đại diện, ngày cấp và nơi cấp CCCD.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            if (! Schema::hasColumn('partners', 'representative_position')) {
                $table->string('representative_position', 100)->nullable()->after('representative_name');
            }
            if (! Schema::hasColumn('partners', 'representative_id_issued_at')) {
                $table->date('representative_id_issued_at')->nullable()->after('representative_id_number');
            }
            if (! Schema::hasColumn('partners', 'representative_id_issued_place')) {
                $table->string('representative_id_issued_place')->nullable()->after('representative_id_issued_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            foreach (['representative_position', 'representative_id_issued_at', 'representative_id_issued_place'] as $column) {
                if (Schema::hasColumn('partners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

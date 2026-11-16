<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Điểm phạt Tiến lên chỉnh được ở CMS: đơn vị chặt / thối / cóng / tới trắng + hệ số quy đổi sang điểm ví.
// Thay cho chop_bonus_points (thưởng cố định mỗi lần chặt).
return new class extends Migration
{
    private const UNITS = [
        'unit_two_black'      => 1,
        'unit_two_red'        => 2,
        'unit_four'           => 4,
        'unit_pair_seq_3'     => 3,
        'unit_pair_seq_extra' => 3,
        'unit_cong'           => 2,
        'unit_instant'        => 2,
    ];

    public function up(): void
    {
        Schema::table('tien_len_settings', function (Blueprint $table) {
            $after = 'daily_points_cap';
            foreach (self::UNITS as $column => $default) {
                if (! Schema::hasColumn('tien_len_settings', $column)) {
                    $table->unsignedSmallInteger($column)->default($default)->after($after);
                }
                $after = $column;
            }
            if (! Schema::hasColumn('tien_len_settings', 'points_per_unit')) {
                // 1 đơn vị phạt = bao nhiêu điểm ví
                $table->unsignedInteger('points_per_unit')->default(1)->after($after);
            }
        });

        if (Schema::hasColumn('tien_len_settings', 'chop_bonus_points')) {
            Schema::table('tien_len_settings', fn (Blueprint $table) => $table->dropColumn('chop_bonus_points'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('tien_len_settings', 'chop_bonus_points')) {
            Schema::table('tien_len_settings', fn (Blueprint $table) => $table->unsignedInteger('chop_bonus_points')->default(1)->after('points_last'));
        }

        foreach ([...array_keys(self::UNITS), 'points_per_unit'] as $column) {
            if (Schema::hasColumn('tien_len_settings', $column)) {
                Schema::table('tien_len_settings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};

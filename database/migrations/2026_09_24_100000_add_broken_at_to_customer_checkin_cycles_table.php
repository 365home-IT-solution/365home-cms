<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_checkin_cycles', function (Blueprint $table) {
            // Thời điểm chu kỳ bị đứt do khách bỏ lỡ 1 ngày — chu kỳ đứt không bao giờ mở lại,
            // lần điểm danh kế tiếp bắt đầu chu kỳ mới từ Ngày 1.
            $table->timestamp('broken_at')->nullable()->after('completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('customer_checkin_cycles', function (Blueprint $table) {
            $table->dropColumn('broken_at');
        });
    }
};

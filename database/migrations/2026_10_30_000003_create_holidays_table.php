<?php

use App\Support\HolidayPrices;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DANH MỤC NGÀY LỄ, TẾT dùng chung (ngoài bảng giá): khai báo 1 lần, rồi thêm từng ngày lễ vào bảng giá nào có áp dụng
 * (mục "Giá ngày lễ, Tết" của bộ giá). partner_id NULL = ngày lễ dùng chung do 365Home quản lý (đối tác chỉ xem + dùng);
 * có partner_id = ngày lễ riêng của đối tác đó.
 * from_day / to_day theo định dạng App\Support\HolidayPrices: lặp hàng năm "MM-DD", đúng 1 năm "YYYY-MM-DD".
 * Bộ giá SAO CHÉP khoảng ngày lúc thêm vào (sửa danh mục không đổi bảng giá đang chạy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('holidays')) {
            Schema::create('holidays', function (Blueprint $table) {
                $table->id();
                $table->char('partner_id', 36)->nullable()->index();
                $table->string('name', 100);
                $table->boolean('recurring')->default(true);
                $table->string('from_day', 10);
                $table->string('to_day', 10);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->text('note')->nullable();
                $table->timestamps();
            });
        }

        // Ngày lễ dựng sẵn dùng chung (Giáng sinh, Tết Dương lịch, 30/4-1/5, Quốc khánh, Tết Âm lịch 2027-2028).
        if (! DB::table('holidays')->whereNull('partner_id')->exists()) {
            foreach (HolidayPrices::PRESETS as $i => $row) {
                DB::table('holidays')->insert([
                    'partner_id' => null, 'name' => $row['name'], 'recurring' => $row['recurring'],
                    'from_day' => $row['from'], 'to_day' => $row['to'], 'is_active' => true, 'sort_order' => $i,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lịch ngừng nhận voucher — xem App\Models\VoucherBlock / App\Services\VoucherBlockService.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('voucher_blocks')) {
            return;
        }

        Schema::create('voucher_blocks', function (Blueprint $table) {
            $table->id();
            $table->char('partner_id', 36)->index();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);

            // Chi nhánh gốc áp dụng; rỗng = mọi chi nhánh của đối tác (kể cả chi nhánh mở sau).
            $table->json('category_ids')->nullable();

            // Ngừng nhận vào: khớp 1 trong các điều kiện dưới đây là ngày đó bị chặn.
            $table->boolean('all_days')->default(false);
            $table->boolean('use_price_board_holidays')->default(false);
            $table->json('date_ranges')->nullable();      // [{name?, from: Y-m-d, to: Y-m-d}]
            $table->json('weekdays')->nullable();         // [1..7] ISO, 1 = Thứ 2
            $table->json('recurring_ranges')->nullable(); // [{name?, from: MM-DD, to: MM-DD}] lặp hằng năm

            $table->date('valid_from')->nullable();       // hiệu lực của quy tắc (trống = luôn)
            $table->date('valid_to')->nullable();
            $table->json('except_dates')->nullable();     // [Y-m-d] vẫn nhận voucher

            $table->json('blocked_groups')->nullable();   // nhóm voucher bị ngừng nhận — xem VoucherBlock::GROUPS
            $table->string('mixed_mode', 10)->default('any'); // any = có 1 đêm bị chặn là chặn cả đơn; all = chỉ khi mọi đêm bị chặn

            $table->text('note')->nullable();
            $table->char('created_by', 36)->nullable(); // users.id là UUID
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_blocks');
    }
};

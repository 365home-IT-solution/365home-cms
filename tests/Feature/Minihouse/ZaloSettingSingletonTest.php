<?php

namespace Tests\Feature\Minihouse;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Minihouse\App\Models\ZaloSetting;
use Tests\TestCase;

// Bug thật đã gặp 2026-09-22: ZaloSetting::current() cũ dùng firstOrCreate(['id' => 1]) — 'id' KHÔNG
// nằm trong $fillable nên create() bên trong ÂM THẦM bỏ qua ['id' => 1] (mass-assignment guard),
// MySQL tự auto-increment thay vào đó. Bình thường "vô tình đúng" vì bảng chỉ có 1 dòng từ lúc còn
// trống (auto-increment tự nhiên ra 1) — nhưng nếu dòng đó từng bị xoá (VD dọn dữ liệu hỏng do
// APP_KEY đổi), current() không bao giờ tìm lại được nữa (luôn where('id', 1)), mỗi lần gọi lại tạo
// thêm 1 dòng rác — hệ quả: MinihouseZaloTokenService khoá lockForUpdate() nhầm dòng, đọc phải cấu
// hình rỗng dù admin vừa lưu refresh_token thật vào dòng khác. Khoá lại: bảng trống hẳn (kể cả không
// có dòng id=1) vẫn luôn tự phục hồi về ĐÚNG 1 dòng duy nhất, gọi lại nhiều lần không tạo thêm dòng.
class ZaloSettingSingletonTest extends TestCase
{
    use DatabaseTransactions;

    public function test_current_recovers_a_single_row_even_when_no_row_has_id_one(): void
    {
        DB::table('minihouse_zalo_settings')->delete();

        $first = ZaloSetting::current();
        $first->update(['app_id' => 'abc']);

        $second = ZaloSetting::current();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('abc', $second->app_id);
        $this->assertSame(1, DB::table('minihouse_zalo_settings')->count());
    }

    public function test_current_is_idempotent_when_row_already_has_id_one(): void
    {
        DB::table('minihouse_zalo_settings')->delete();

        ZaloSetting::current();
        ZaloSetting::current();
        ZaloSetting::current();

        $this->assertSame(1, DB::table('minihouse_zalo_settings')->count());
    }
}

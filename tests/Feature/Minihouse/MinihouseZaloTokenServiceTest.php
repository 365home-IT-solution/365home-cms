<?php

namespace Tests\Feature\Minihouse;

use App\Services\ZaloTokenService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Services\MinihouseZaloTokenService;
use Tests\TestCase;

// Bug thật đã gặp (2026-09-22): MiniHouse từng tự quản lý access_token/refresh_token RIÊNG (đọc/ghi
// ZaloSetting, bảng minihouse_zalo_settings), giả định có 1 Zalo OA khác hẳn OA của Home — nhưng
// production thực tế dùng CHUNG đúng 1 Zalo OA cho cả 2 hệ thống. Refresh_token của Zalo chỉ dùng
// được 1 lần, nên 2 nơi quản lý độc lập liên tục giẫm chân nhau: bên nào refresh trước làm bên kia
// cầm token đã bị Zalo thu hồi — gây "Invalid refresh token." lặp lại ở CẢ Home lẫn MiniHouse. Sửa
// tận gốc: MiniHouse không tự refresh nữa, chỉ MƯỢN access_token từ App\Services\ZaloTokenService
// (của Home) — CHỈ CÓ ĐÚNG 1 nơi quản lý vòng đời token cho toàn hệ thống. Test này khoá lại đúng
// việc uỷ quyền đó, thay cho bộ test cũ (retry/lock/refresh riêng — không còn áp dụng, class giờ chỉ
// còn 1 dòng logic).
class MinihouseZaloTokenServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_get_access_token_delegates_to_the_shared_home_zalo_token_service(): void
    {
        $this->mock(ZaloTokenService::class, function ($mock) {
            $mock->shouldReceive('getAccessToken')->once()->andReturn('shared-access-token');
        });

        $token = app(MinihouseZaloTokenService::class)->getAccessToken();

        $this->assertSame('shared-access-token', $token);
    }
}

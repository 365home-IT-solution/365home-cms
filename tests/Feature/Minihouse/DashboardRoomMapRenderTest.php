<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Smoke test cho sơ đồ phòng sau khi đổi UI (Modules/Minihouse/Resources/views/filament/widgets/
// partials/room-card.blade.php) — chỉ còn hiện tên phòng + màu, thông tin đầy đủ chuyển vào tooltip
// (title). Đảm bảo trang Tổng quan vẫn render được bình thường sau khi đổi markup.
class DashboardRoomMapRenderTest extends TestCase
{
    use DatabaseTransactions;

    public function test_dashboard_with_room_map_renders_ok(): void
    {
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);

        $response = $this->actingAs($admin)->get('/minihouse-admin');
        $response->assertOk();
    }
}

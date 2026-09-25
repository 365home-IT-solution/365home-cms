<?php

namespace Tests\Feature\Notifications;

use App\Filament\Livewire\ScopedDatabaseNotifications;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

// Bug thật đã gặp: 2 panel Filament "admin" (Home) và "minihouse-admin" dùng CHUNG bảng
// `notifications` (gắn theo User, không theo panel) — 1 tài khoản quản lý cả 2 (VD super_admin) thấy
// LẪN chuông thông báo của module kia. Sửa bằng ScopedDatabaseNotifications (đăng ký đè component
// Livewire "filament.livewire.database-notifications" trong AppServiceProvider::boot()) lọc theo cờ
// data->viewData->module. Test này khoá lại: (1) thông báo Home (KHÔNG có cờ module) vẫn hiện đầy đủ
// ở panel Home như trước khi sửa — không được vô tình lọc mất; (2) thông báo module=minihouse KHÔNG
// lọt sang panel Home; (3) panel MiniHouse CHỈ thấy đúng thông báo module=minihouse.
class AdminNotificationPanelScopeTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::role('super_admin')->first() ?? User::factory()->create();
    }

    public function test_livewire_component_is_overridden_to_scoped_class(): void
    {
        $resolved = app(\Livewire\Mechanisms\ComponentRegistry::class)->getClass('filament.livewire.database-notifications');

        $this->assertSame(ScopedDatabaseNotifications::class, $resolved);
    }

    public function test_home_panel_sees_untagged_notifications_unaffected(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Notification::make()->title('Home order notif')->viewData(['type' => 'order_pending'])->sendToDatabase($this->admin);

        $titles = (new ScopedDatabaseNotifications())->getNotificationsQuery()->pluck('data')->map(fn ($d) => $d['title'])->all();

        $this->assertContains('Home order notif', $titles);
    }

    public function test_home_panel_does_not_see_minihouse_tagged_notifications(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);

        Notification::make()->title('MiniHouse chat notif')->viewData(['type' => 'minihouse_message', 'module' => 'minihouse'])->sendToDatabase($this->admin);

        $titles = (new ScopedDatabaseNotifications())->getNotificationsQuery()->pluck('data')->map(fn ($d) => $d['title'])->all();

        $this->assertNotContains('MiniHouse chat notif', $titles);
    }

    public function test_minihouse_panel_only_sees_minihouse_tagged_notifications(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('minihouse-admin'));
        $this->actingAs($this->admin, 'web');

        Notification::make()->title('Home order notif 2')->viewData(['type' => 'order_pending'])->sendToDatabase($this->admin);
        Notification::make()->title('MiniHouse chat notif 2')->viewData(['type' => 'minihouse_message', 'module' => 'minihouse'])->sendToDatabase($this->admin);

        $titles = (new ScopedDatabaseNotifications())->getNotificationsQuery()->pluck('data')->map(fn ($d) => $d['title'])->all();

        $this->assertContains('MiniHouse chat notif 2', $titles);
        $this->assertNotContains('Home order notif 2', $titles);
    }
}

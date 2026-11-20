<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Support\AdminNotificationCategory;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// GET/PUT /api/admin/notification-settings + ?type= / ?exclude_type= + unread_by_type của
// GET /api/admin/notifications. Dùng 1 user mới (không phải super_admin) để chỉ thấy đúng thông báo
// do test tạo.
class AdminNotificationSettingsAndFilterTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    private function seedNotification(?string $type, bool $read = false): void
    {
        $viewData = $type === null ? [] : ['type' => $type];
        Notification::make()->title('t-' . ($type ?? 'legacy'))->viewData($viewData)->sendToDatabase($this->user);

        // Gọi khi chưa có thông báo nào khác chưa đọc → markAsRead cả lô chỉ chạm đúng dòng vừa tạo
        // (không dùng latest(): nhiều dòng cùng giây thì thứ tự không xác định).
        if ($read) {
            $this->user->unreadNotifications->markAsRead();
        }
    }

    public function test_type_mapping(): void
    {
        $this->assertSame('order', AdminNotificationCategory::of('booking_confirmation'));
        $this->assertSame('order', AdminNotificationCategory::of(null));
        $this->assertSame('order', AdminNotificationCategory::of('something_new'));
        $this->assertSame('payment', AdminNotificationCategory::of('payment'));
        $this->assertSame('payment', AdminNotificationCategory::of('escrow_low'));
        $this->assertSame('chat', AdminNotificationCategory::of('message'));
        $this->assertSame('stay', AdminNotificationCategory::of('checkin'));
        $this->assertSame('approval', AdminNotificationCategory::of('approval_requested'));
        $this->assertSame('partner', AdminNotificationCategory::of('partner_payment_channel_changed'));
        $this->assertSame('subscription', AdminNotificationCategory::of('subscription_paid'));
        $this->assertSame('app_error', AdminNotificationCategory::of('app_error'));
    }

    public function test_settings_default_then_partial_update_persists(): void
    {
        $this->getJson('/api/admin/notification-settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.types.chat', true)
            ->assertJsonCount(8, 'data.types');

        $this->putJson('/api/admin/notification-settings', ['types' => ['chat' => false]])
            ->assertOk()
            ->assertJsonPath('data.types.chat', false)
            ->assertJsonPath('data.types.order', true);

        // Gửi riêng `enabled` không làm mất cài đặt nhóm đã lưu.
        $this->putJson('/api/admin/notification-settings', ['enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.types.chat', false);

        $this->assertFalse($this->user->fresh()->wantsNotificationCategory('order'));
    }

    public function test_settings_reject_unknown_type_key_and_non_boolean(): void
    {
        $this->putJson('/api/admin/notification-settings', ['types' => ['bogus' => true]])->assertStatus(422);
        $this->putJson('/api/admin/notification-settings', ['types' => ['chat' => 'maybe']])->assertStatus(422);
    }

    public function test_list_filters_and_unread_by_type(): void
    {
        $this->seedNotification('message', read: true); // phải tạo đầu tiên, xem seedNotification()
        $this->seedNotification('message');
        $this->seedNotification('message');
        $this->seedNotification('payment');
        $this->seedNotification('booking_confirmation');
        $this->seedNotification(null);                 // thông báo cũ không có type → order

        $all = $this->getJson('/api/admin/notifications')->assertOk();
        $all->assertJsonPath('meta.total', 6)
            ->assertJsonPath('unread_count', 5)
            ->assertJsonPath('unread_by_type.chat', 2)
            ->assertJsonPath('unread_by_type.payment', 1)
            ->assertJsonPath('unread_by_type.order', 2)
            ->assertJsonPath('unread_by_type.stay', 0);
        $this->assertSame(5, array_sum($all->json('unread_by_type')));

        $this->getJson('/api/admin/notifications?type=chat')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/admin/notifications?type=chat,payment')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/admin/notifications?type[]=payment')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/notifications?type=order')->assertJsonPath('meta.total', 2);

        // exclude giữ lại cả thông báo cũ không có type (NULL)
        $this->getJson('/api/admin/notifications?exclude_type=chat')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/admin/notifications?exclude_type=order')->assertJsonPath('meta.total', 4);
        $this->getJson('/api/admin/notifications?exclude_type=chat,payment')->assertJsonPath('meta.total', 2);

        // Lọc danh sách không đổi số chưa đọc tổng.
        $this->getJson('/api/admin/notifications?type=payment')->assertJsonPath('unread_count', 5);
    }
}

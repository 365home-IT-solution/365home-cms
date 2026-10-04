<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\CustomerCccdVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Payment\App\Services\CccdScannerService;
use Modules\Payment\Entities\Order;
use Tests\TestCase;

// Các API LƯU nhận thêm cccd_qr_image / guests[i][qr_image] / companions[i][qr_image] (1 ảnh mặt có
// mã QR) SONG SONG với cccd_front + cccd_back — không gửi ảnh QR thì luồng 2 mặt chạy y như cũ.
// Các test tạo đơn dừng ở bước "phòng không tồn tại" (404) để không tạo đơn thật/gọi cổng thanh toán.
class CccdQrImageSaveTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Storage::fake('public');
        // Tạo/sửa đơn có bắn thông báo (push, WS realtime) qua Http — chặn hết, không gọi ra ngoài.
        Http::fake();
    }

    /** Mỗi lần quét trả lần lượt 1 phần tử của $results (phần tử cuối lặp lại). */
    private function mockQrScans(?array ...$results): void
    {
        $this->mock(CccdScannerService::class, function ($mock) use ($results) {
            $mock->shouldReceive('scanQrImage')->andReturn(...$results);
        });
    }

    private function person(array $override = []): array
    {
        return array_merge([
            'cccd'        => '087204016918',
            'old_id'      => '',
            'full_name'   => 'Nguyễn Văn A',
            'dob'         => '12/05/2004',
            'gender'      => 'Nam',
            'address'     => 'Đồng Tháp',
            'issued_date' => '01/01/2022',
            'source'      => 'qr',
        ], $override);
    }

    private function otherPerson(): array
    {
        return $this->person(['cccd' => '079199012345', 'full_name' => 'Trần Thị B', 'dob' => '20/08/1999', 'gender' => 'Nữ']);
    }

    private function image(string $name = 'cccd.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1200, 800);
    }

    /** @return array{0: Customer, 1: array<string, string>} */
    private function customerWithHeaders(array $attributes = []): array
    {
        $customer = Customer::create(array_merge([
            'fullname' => 'Khách Test',
            'phone'    => '09' . random_int(10000000, 99999999),
            'status'   => Customer::STATUS_ACTIVE,
        ], $attributes));

        return [$customer, ['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken, 'Accept' => 'application/json']];
    }

    private function adminHeaders(): array
    {
        $admin = User::role('super_admin')->first();

        return ['Authorization' => 'Bearer ' . $admin->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function guestOrderPayload(array $extra = []): array
    {
        return array_merge([
            'type'         => 'slot',
            'room_id'      => 'khong-ton-tai',
            'buyer_name'   => 'Khách Vãng Lai',
            'buyer_phone'  => '0900000001',
            'guest_count'  => 1,
            'slots'        => [['timeslot_id' => 1]],
        ], $extra);
    }

    private function pendingOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'amount'         => 100000,
            'full_amount'    => 100000,
            'description'    => 'Đơn test CCCD QR',
            'buyer_name'     => 'Khách Vãng Lai',
            'buyer_phone'    => '09' . random_int(10000000, 99999999),
            'payment_method' => 'cod',
            'status'         => 'pending',
            'guest_count'    => 1,
            'customer_id'    => null,
        ], $attributes));
    }

    // ── POST /api/guest/orders ───────────────────────────────────────────────

    public function test_guest_order_accepts_qr_image_instead_of_front_and_back(): void
    {
        $this->mockQrScans($this->person());

        // Qua được bước CCCD (không đòi cccd_front/back), dừng ở bước phòng không tồn tại.
        $this->post('/api/guest/orders', $this->guestOrderPayload(['cccd_qr_image' => $this->image()]), ['Accept' => 'application/json'])
            ->assertStatus(404);

        $this->assertCount(1, Storage::disk('public')->allFiles('cccd/qr'));
    }

    public function test_guest_order_without_any_cccd_image_still_requires_front_and_back(): void
    {
        $this->post('/api/guest/orders', $this->guestOrderPayload(), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cccd_front', 'cccd_back'])
            ->assertJsonMissingValidationErrors(['cccd_qr_image']);
    }

    public function test_guest_order_rejects_unreadable_qr_and_stores_nothing(): void
    {
        $this->mockQrScans(null);

        $this->post('/api/guest/orders', $this->guestOrderPayload(['cccd_qr_image' => $this->image()]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_qr_unreadable', 'field' => 'cccd_qr_image']);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_guest_order_qr_image_keeps_the_existing_age_rule(): void
    {
        $this->mockQrScans($this->person(['cccd' => '087210016918', 'dob' => '12/05/2010']));

        $this->post('/api/guest/orders', $this->guestOrderPayload(['cccd_qr_image' => $this->image()]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Người đặt phòng phải đủ 18 tuổi trở lên.']);

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ── POST /api/guest/orders/{order_code} ──────────────────────────────────

    public function test_guest_order_update_replaces_booker_cccd_with_qr_image(): void
    {
        $this->mockQrScans($this->person());
        $order = $this->pendingOrder(['cccd_front' => 'cccd/old-front.jpg', 'cccd_back' => 'cccd/old-back.jpg']);

        $this->post("/api/guest/orders/{$order->order_code}", [
            'buyer_phone'   => $order->buyer_phone,
            'cccd_qr_image' => $this->image(),
        ], ['Accept' => 'application/json'])->assertOk();

        $order->refresh();
        $this->assertNotNull($order->cccd_qr_image);
        $this->assertTrue(Storage::disk('public')->exists($order->cccd_qr_image));
        $this->assertNull($order->cccd_front);
        $this->assertNull($order->cccd_back);
        $this->assertSame('087204016918', $order->cccd_data['cccd']);
    }

    // ── POST /api/orders (đã đăng nhập) ──────────────────────────────────────

    public function test_logged_in_order_accepts_profile_saved_with_qr_image_only(): void
    {
        [, $headers] = $this->customerWithHeaders(['cccd_qr_image' => 'cccd/qr/profile.jpg', 'cccd_data' => $this->person()]);

        // Hồ sơ chỉ có ảnh QR vẫn qua được bước "cần cập nhật CCCD", dừng ở bước phòng không tồn tại.
        $this->withHeaders($headers)->postJson('/api/orders', ['type' => 'slot', 'room_id' => 'khong-ton-tai', 'guest_count' => 1, 'slots' => [['timeslot_id' => 1]]])
            ->assertStatus(404);
    }

    public function test_logged_in_order_still_requires_cccd_in_profile(): void
    {
        [, $headers] = $this->customerWithHeaders();

        $this->withHeaders($headers)->postJson('/api/orders', ['type' => 'slot', 'room_id' => 'khong-ton-tai', 'guest_count' => 1, 'slots' => [['timeslot_id' => 1]]])
            ->assertStatus(422)
            ->assertJson(['error' => 'cccd_required']);
    }

    // ── POST /api/auth/me ────────────────────────────────────────────────────

    public function test_profile_saves_own_cccd_from_qr_image(): void
    {
        $this->mockQrScans($this->person());
        [$customer, $headers] = $this->customerWithHeaders();

        $this->withHeaders($headers)->post('/api/auth/me', ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJsonPath('cccd_data.cccd', '087204016918')
            ->assertJsonPath('cccd_front', null);

        $customer->refresh();
        $this->assertNotNull($customer->cccd_qr_image);
        $this->assertTrue(Storage::disk('public')->exists($customer->cccd_qr_image));
        $this->assertSame(CustomerCccdVerification::SOURCE_APP, $customer->cccdVerifications()->latest('id')->value('source'));
    }

    public function test_profile_rejects_unreadable_qr_and_keeps_profile_unchanged(): void
    {
        $this->mockQrScans(null);
        [$customer, $headers] = $this->customerWithHeaders();

        $this->withHeaders($headers)->post('/api/auth/me', ['fullname' => 'Tên Mới', 'cccd_qr_image' => $this->image()])
            ->assertStatus(422)
            ->assertJson(['code' => 'cccd_qr_unreadable', 'field' => 'cccd_qr_image']);

        $customer->refresh();
        $this->assertSame('Khách Test', $customer->fullname);
        $this->assertNull($customer->cccd_qr_image);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_profile_adds_companion_from_qr_image_and_reports_sync_status(): void
    {
        $this->mockQrScans($this->otherPerson());
        [$customer, $headers] = $this->customerWithHeaders(['cccd_qr_image' => 'cccd/qr/profile.jpg', 'cccd_data' => $this->person()]);

        $this->withHeaders($headers)->post('/api/auth/me', ['companions' => [['qr_image' => $this->image()]]])
            ->assertOk()
            ->assertJsonPath('companions_sync.0.status', 'created')
            ->assertJsonPath('companions.0.cccd_front', null)
            ->assertJsonPath('companions.0.cccd_data.cccd', '079199012345');

        $companion = $customer->companions()->first();
        $this->assertNotNull($companion->cccd_qr_image);
        $this->assertSame('Trần Thị B', $companion->full_name);

        // Gửi lại đúng người đó → cập nhật, không tạo trùng.
        $this->withHeaders($headers)->post('/api/auth/me', ['companions' => [['qr_image' => $this->image()]]])
            ->assertOk()
            ->assertJsonPath('companions_sync.0.status', 'updated');

        $this->assertSame(1, $customer->companions()->count());
    }

    public function test_profile_companion_without_any_image_keeps_the_old_required_error(): void
    {
        [, $headers] = $this->customerWithHeaders();

        $this->withHeaders($headers)->postJson('/api/auth/me', ['companions' => [['full_name' => 'Không Ảnh']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                // Đúng câu báo lỗi "bắt buộc" như trước khi có qr_image.
                'companions.0.cccd_front' => __('validation.required', ['attribute' => 'companions.0.cccd_front']),
                'companions.0.cccd_back'  => __('validation.required', ['attribute' => 'companions.0.cccd_back']),
            ]);
    }

    // ── Quản trị ─────────────────────────────────────────────────────────────

    public function test_admin_booking_accepts_qr_image_for_main_guest(): void
    {
        $this->mockQrScans($this->person());

        $payload = ['type' => 'slot', 'room_id' => 'khong-ton-tai', 'guest_count' => 1, 'buyer_name' => 'Khách', 'buyer_phone' => '0900000002', 'slots' => [['timeslot_id' => 1]]];

        // Không gửi ảnh nào → vẫn báo thiếu 2 mặt như cũ.
        $this->withHeaders($this->adminHeaders())->postJson('/api/admin/orders', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cccd_front', 'cccd_back']);

        // Gửi ảnh QR → qua bước CCCD, dừng ở bước phòng không tồn tại.
        $this->withHeaders($this->adminHeaders())->post('/api/admin/orders', $payload + ['cccd_qr_image' => $this->image()])
            ->assertStatus(404);
    }

    public function test_admin_order_update_saves_qr_images_for_main_guest_and_companion(): void
    {
        $this->mockQrScans($this->person(), $this->otherPerson());
        $order = $this->pendingOrder();

        $this->withHeaders($this->adminHeaders())->post("/api/admin/orders/{$order->order_code}", [
            'cccd_qr_image' => $this->image('main.jpg'),
            'guests'        => [2 => ['qr_image' => $this->image('guest.jpg')]],
        ])->assertOk()
            ->assertJsonPath('order.cccd_data.cccd', '087204016918')
            ->assertJsonPath('order.cccd_front', null)
            ->assertJsonPath('order.guests.0.cccd_data.cccd', '079199012345')
            ->assertJsonPath('order.guests.0.cccd_front', null);

        $order->refresh();
        $this->assertTrue(Storage::disk('public')->exists($order->cccd_qr_image));
        $guest = $order->guestCccds()->where('guest_index', 2)->first();
        $this->assertTrue(Storage::disk('public')->exists($guest->cccd_qr_image));
    }

    public function test_admin_order_update_keeps_qr_image_even_when_qr_is_unreadable(): void
    {
        $this->mockQrScans(null);
        $order = $this->pendingOrder();

        $this->withHeaders($this->adminHeaders())->post("/api/admin/orders/{$order->order_code}", ['cccd_qr_image' => $this->image()])
            ->assertOk()
            ->assertJsonPath('order.cccd_data', null);

        $this->assertNotNull($order->refresh()->cccd_qr_image);
    }

    public function test_admin_customer_update_saves_qr_image(): void
    {
        $this->mockQrScans($this->person());
        [$customer] = $this->customerWithHeaders();

        $this->withHeaders($this->adminHeaders())->post("/api/admin/customers/{$customer->id}", ['cccd_qr_image' => $this->image()])
            ->assertOk();

        $customer->refresh();
        $this->assertTrue(Storage::disk('public')->exists($customer->cccd_qr_image));
        $this->assertSame('087204016918', $customer->cccd_data['cccd']);
    }

    public function test_admin_companion_can_be_created_and_updated_from_qr_image(): void
    {
        $this->mockQrScans($this->otherPerson());
        [$customer] = $this->customerWithHeaders();
        $headers = $this->adminHeaders();

        $id = $this->withHeaders($headers)->post("/api/admin/customers/{$customer->id}/companions", ['companions' => [['qr_image' => $this->image()]]])
            ->assertCreated()
            ->assertJsonPath('companions.0.full_name', 'Trần Thị B')
            ->assertJsonPath('companions.0.cccd_front_url', null)
            ->json('companions.0.id');

        $first = $customer->companions()->find($id)->cccd_qr_image;
        $this->assertTrue(Storage::disk('public')->exists($first));

        $this->withHeaders($headers)->post("/api/admin/customers/{$customer->id}/companions/{$id}", ['cccd_qr_image' => $this->image('new.jpg')])
            ->assertOk();

        $this->assertNotSame($first, $customer->companions()->find($id)->cccd_qr_image);
    }
}

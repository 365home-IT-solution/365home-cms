<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Partner;
use App\Services\Payment\PartnerPayOsSalesGate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\BranchPayOsAccount;
use Modules\Payment\Entities\Order;
use Modules\Payment\Entities\PartnerPayOsAccount;
use Modules\Product\App\Models\Product;
use Tests\TestCase;

// Đối tác/chi nhánh chưa liên kết tài khoản PayOS thì phòng không được bán online: ẩn khỏi danh sách và API đặt phòng của khách
// (có tài khoản + vãng lai) trả 423 PARTNER_PAYOS_REQUIRED, không tạo đơn. Xem App\Services\Payment\PartnerPayOsSalesGate.
class PartnerPayOsSalesGateTest extends TestCase
{
    use DatabaseTransactions;

    private Partner $partner;

    private Product $room;

    protected function setUp(): void
    {
        parent::setUp();

        config(['partner_flow.require_payos_channel' => true]);

        $room = Product::query()->where('is_activated', true)->whereHas('categories')->first();
        if (! $room) {
            $this->markTestSkipped('Không có phòng đang bật nào gắn chi nhánh.');
        }

        // Đối tác Homestay mới (chưa có kênh PayOS nào) làm chủ phòng — rollback sau mỗi test.
        $this->partner = Partner::create(['name' => 'PayOS gate ' . uniqid(), 'partner_type' => Partner::TYPE_HOMESTAY, 'status' => true]);
        Product::withoutGlobalScopes()->whereKey($room->id)->update(['partner_id' => $this->partner->id]);
        $this->room = $room->fresh();
    }

    private function slotBooking(array $extra = []): array
    {
        return [
            'type'        => 'slot',
            'room_id'     => (string) $this->room->id,
            'guest_count' => 1,
            'slots'       => [['timeslot_id' => 1, 'date' => now()->addDay()->format('Y-m-d')]],
        ] + $extra;
    }

    private function guestBooking(): array
    {
        return $this->slotBooking([
            'buyer_name'  => 'Khach Test',
            'buyer_phone' => '0912345678',
            'cccd_front'  => UploadedFile::fake()->image('front.jpg'),
            'cccd_back'   => UploadedFile::fake()->image('back.jpg'),
        ]);
    }

    private function partnerOrderCount(): int
    {
        return Order::withoutGlobalScopes()->where('partner_id', $this->partner->id)->count();
    }

    private function branchCategoryId(): int
    {
        $category = $this->room->categories()->first();
        for ($i = 0; $i < 5 && $category->parent_id; $i++) {
            $category = Category::find($category->parent_id) ?? $category;
        }

        return (int) $category->id;
    }

    private function fakeKeys(): array
    {
        return ['is_active' => true, 'client_id' => 'test-client', 'api_key' => 'test-api', 'checksum_key' => 'test-checksum'];
    }

    public function test_guest_api_rejects_room_of_unlinked_partner(): void
    {
        $this->postJson('/api/guest/orders', $this->guestBooking())
            ->assertStatus(423)
            ->assertJson(['code' => 'PARTNER_PAYOS_REQUIRED']);

        $this->assertSame(0, $this->partnerOrderCount());
    }

    public function test_customer_api_rejects_room_of_unlinked_partner(): void
    {
        $customer = Customer::query()->first();
        if (! $customer) {
            $this->markTestSkipped('Không có khách hàng nào để đăng nhập.');
        }
        Sanctum::actingAs($customer, [], 'sanctum');

        $this->postJson('/api/orders', $this->slotBooking())
            ->assertStatus(423)
            ->assertJson(['code' => 'PARTNER_PAYOS_REQUIRED']);

        $this->assertSame(0, $this->partnerOrderCount());
    }

    public function test_unlinked_room_is_hidden_from_listing(): void
    {
        $this->assertFalse(PartnerPayOsSalesGate::canSellRoom($this->room));
        $this->assertFalse(Product::query()->activeBranch()->whereKey($this->room->id)->exists());
    }

    public function test_partner_level_payos_account_allows_booking(): void
    {
        PartnerPayOsAccount::create(['partner_id' => $this->partner->id] + $this->fakeKeys());

        $this->assertTrue(PartnerPayOsSalesGate::canSellRoom($this->room));
        $this->assertTrue(Product::query()->activeBranch()->whereKey($this->room->id)->exists());

        // Qua được cổng — lỗi (nếu có) là ở bước sau (CCCD giả không đọc được QR...), không phải 423.
        $response = $this->postJson('/api/guest/orders', $this->guestBooking());
        $this->assertNotSame(423, $response->status(), $response->getContent());
    }

    public function test_branch_level_payos_account_allows_only_that_branch(): void
    {
        BranchPayOsAccount::create(['category_id' => $this->branchCategoryId()] + $this->fakeKeys());

        $this->assertTrue(PartnerPayOsSalesGate::canSellRoom($this->room));

        $response = $this->postJson('/api/guest/orders', $this->guestBooking());
        $this->assertNotSame(423, $response->status(), $response->getContent());
    }

    public function test_inactive_or_incomplete_account_still_blocks(): void
    {
        PartnerPayOsAccount::create(['partner_id' => $this->partner->id, 'is_active' => false] + array_diff_key($this->fakeKeys(), ['is_active' => 1]));
        $this->assertFalse(PartnerPayOsSalesGate::canSellRoom($this->room));

        PartnerPayOsAccount::where('partner_id', $this->partner->id)->update(['is_active' => true]);
        PartnerPayOsAccount::where('partner_id', $this->partner->id)->first()->forceFill(['checksum_key' => ''])->save();
        $this->assertFalse(PartnerPayOsSalesGate::canSellRoom($this->room));
    }

    public function test_gate_can_be_turned_off(): void
    {
        config(['partner_flow.require_payos_channel' => false]);

        $this->assertTrue(PartnerPayOsSalesGate::canSellRoom($this->room));
    }
}

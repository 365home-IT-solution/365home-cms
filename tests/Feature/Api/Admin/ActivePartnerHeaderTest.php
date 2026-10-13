<?php

namespace Tests\Feature\Api\Admin;

use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Modules\Category\Entities\Category;
use Modules\Payment\Entities\Order;
use Tests\TestCase;

// super_admin gửi header X-Partner-Id → API chỉ trả dữ liệu của đúng đối tác đó; không gửi thì
// giữ nguyên hành vi cũ (thấy mọi đối tác). Tài khoản khác bỏ qua header.
class ActivePartnerHeaderTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function headersFor(User $user, array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer ' . $user->createToken('t')->plainTextToken,
            'Accept'        => 'application/json',
        ], $extra);
    }

    private function partnerWithBranch(): ?string
    {
        return Category::withoutGlobalScopes()
            ->whereNull('parent_id')
            ->where('category_type', 'product')
            ->whereNotNull('partner_id')
            ->value('partner_id');
    }

    public function test_super_admin_header_narrows_branches_to_one_partner(): void
    {
        $admin     = User::role('super_admin')->first();
        $partnerId = $this->partnerWithBranch();
        $this->assertNotNull($partnerId, 'Cần ít nhất 1 chi nhánh có đối tác trong DB local.');

        $all = $this->withHeaders($this->headersFor($admin))->getJson('/api/admin/me')->assertOk();
        $this->assertNull($all->json('user.active_partner'));

        $scoped = $this->withHeaders($this->headersFor($admin, ['X-Partner-Id' => $partnerId]))
            ->getJson('/api/admin/me')->assertOk();

        $this->assertSame($partnerId, $scoped->json('user.active_partner.id'));
        $this->assertSame($admin->getRawOriginal('partner_id'), $scoped->json('user.partner_id'));
        $this->assertTrue($scoped->json('user.is_super_admin'));

        $expected = Category::withoutGlobalScopes()
            ->whereNull('parent_id')->where('category_type', 'product')
            ->where('partner_id', $partnerId)->pluck('id')->sort()->values()->all();
        $actual = collect($scoped->json('user.categories'))->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $actual);
    }

    public function test_super_admin_header_filters_orders_by_partner(): void
    {
        $admin     = User::role('super_admin')->first();
        $partnerId = Order::withoutGlobalScopes()->whereNotNull('partner_id')->value('partner_id');
        $this->assertNotNull($partnerId, 'Cần ít nhất 1 đơn có đối tác trong DB local.');

        $rows = $this->withHeaders($this->headersFor($admin, ['X-Partner-Id' => $partnerId]))
            ->getJson('/api/admin/orders?per_page=100')->assertOk()->json('data');

        foreach ($rows as $row) {
            $this->assertSame($partnerId, Order::withoutGlobalScopes()->whereKey($row['id'])->value('partner_id'));
        }
    }

    public function test_unknown_partner_returns_422(): void
    {
        $admin = User::role('super_admin')->first();

        $this->withHeaders($this->headersFor($admin, ['X-Partner-Id' => '00000000-0000-0000-0000-000000000000']))
            ->getJson('/api/admin/me')
            ->assertStatus(422);
    }

    public function test_header_does_not_change_scope_of_partner_account(): void
    {
        $user = User::whereNotNull('partner_id')
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->first();
        $this->assertNotNull($user, 'Cần ít nhất 1 tài khoản thuộc đối tác trong DB local.');

        $other = Partner::query()->where('id', '!=', $user->partner_id)->value('id');

        $res = $this->withHeaders($this->headersFor($user, $other ? ['X-Partner-Id' => $other] : []))
            ->getJson('/api/admin/me');

        if ($res->status() === 200) {
            $this->assertSame($user->partner_id, $res->json('user.partner_id'));
            $this->assertNull($res->json('user.active_partner'));
        } else {
            // Tài khoản này không đăng nhập được API admin (vd bị khoá gói) — header không tạo ra khác biệt.
            $this->assertNotSame(422, $res->status());
        }
    }
}

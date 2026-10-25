<?php

namespace Tests\Feature\Api\Admin;

use App\Models\User;
use App\Services\BranchServiceSync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Chi tiết đơn: dịch vụ thêm được chỉ là dịch vụ của chi nhánh đơn đặt, không phải mọi dịch vụ.
class OrderServicesByBranchTest extends TestCase
{
    use DatabaseTransactions;

    public function test_services_are_limited_to_the_order_branch(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $token = User::role('super_admin')->first()->createToken('t')->plainTextToken;

        $order = DB::table('orders')
            ->whereNotNull('order_code')
            ->whereIn('id', DB::table('order_items')->whereIn('product_id', DB::table('room_additional_service_assigns')->select('room_id'))->select('order_id'))
            ->first(['id', 'order_code']);

        if (! $order) {
            $this->markTestSkipped('Không có đơn nào có phòng gắn dịch vụ.');
        }

        $roomId    = DB::table('order_items')->where('order_id', $order->id)->value('product_id');
        $branchIds = app(BranchServiceSync::class)->rootBranchIdsForRoomId((string) $roomId);
        $expected  = DB::table('additional_services')->where('is_active', true)
            ->whereIn('id', DB::table('additional_service_branches')->whereIn('category_id', $branchIds)->select('additional_service_id'))
            ->pluck('id')->sort()->values()->all();

        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];

        $listed = collect($this->withHeaders($headers)->getJson('/api/admin/services?order_code=' . $order->order_code)->assertOk()->json('data'))
            ->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $listed);

        $available = collect($this->withHeaders($headers)->getJson('/api/admin/orders/' . $order->order_code)->assertOk()->json('order.available_services'))
            ->pluck('id')->sort()->values()->all();
        $this->assertSame($expected, $available);

        $this->withHeaders($headers)->getJson('/api/admin/services?order_code=khong-ton-tai')->assertNotFound();
    }
}

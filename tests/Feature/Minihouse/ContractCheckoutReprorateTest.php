<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Audit phát hiện: API checkout()/cancel()/transferRoom() KHÔNG gọi ContractEarlyEndService::
// reprorate() như bản Filament (EditContract) — hoá đơn tháng hiện tại vẫn tính tiền phòng cho cả
// tháng dù khách trả phòng/chuyển đi giữa tháng, gây THU THỪA nếu hợp đồng mới/phòng mới tính tiếp
// đúng những ngày đó. Test này khoá lại việc API đã gọi đúng service dùng chung với Filament.
class ContractCheckoutReprorateTest extends TestCase
{
    use DatabaseTransactions;

    public function test_checkout_via_api_shrinks_current_invoice_to_actual_days_occupied(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999)]);

        $monthStart = now()->startOfMonth();
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => $monthStart->copy()->subMonths(2), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        // Hoá đơn tháng hiện tại đã lập SẴN cho CẢ THÁNG (giả định ở tới hết tháng).
        $invoice = Invoice::create([
            'contract_id' => $contract->id,
            'month'        => $monthStart,
            'period_start' => $monthStart->toDateString(),
            'period_end'   => $monthStart->copy()->endOfMonth()->toDateString(),
            'room_price'   => 3000000,
            'total_amount' => 3000000,
            'status'       => Invoice::STATUS_UNPAID,
        ]);

        // Khách trả phòng vào ngày 11 của tháng — chỉ thực ở 10 ngày (1-10).
        $checkoutAt = $monthStart->copy()->addDays(10);

        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson("/api/admin/minihouse/contracts/{$contract->id}/checkout", [
                'checkout_at' => $checkoutAt->toDateString(),
                'deposit_refunded_amount' => 0,
            ]);

        $response->assertOk();

        $invoice->refresh();
        // Hoá đơn phải được RÚT NGẮN lại — total_amount < 3.000.000đ (không còn tính đủ cả tháng).
        $this->assertLessThan(3000000, $invoice->total_amount);
        $this->assertSame($checkoutAt->copy()->subDay()->toDateString(), $invoice->period_end->toDateString());
    }
}

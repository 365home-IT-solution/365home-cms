<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Exceptions\CannotDeletePaidInvoiceException;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Người dùng phát hiện: xoá 1 hoá đơn ĐÃ THANH TOÁN làm mất luôn lịch sử thanh toán ở Portal — đào
// sâu hơn thì phát hiện InvoiceObserver::deleting() còn XOÁ THẬT (không phải xoá mềm) InvoicePayment
// + kéo theo xoá luôn dòng "Thu" trong sổ Thu Chi, tức là mất vĩnh viễn bằng chứng đã thu tiền thật.
// Theo lựa chọn của người dùng: CHẶN HẲN việc xoá hoá đơn đã có thanh toán được duyệt.
class InvoiceDeletionGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function makePaidInvoice(): Invoice
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999)]);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
        $invoice = Invoice::create([
            'contract_id' => $contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 3000000,
            'status' => Invoice::STATUS_PAID,
        ]);
        // InvoicePaymentObserver tự sinh 1 dòng "Thu" (Transaction) khi khoản thanh toán ở trạng
        // thái approved — không tự tạo tay ở đây, tránh đụng ràng buộc UNIQUE(invoice_payment_id).
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_CASH, 'status' => InvoicePayment::STATUS_APPROVED,
            'approved_at' => now(),
        ]);

        return $invoice;
    }

    public function test_deleting_a_paid_invoice_throws_and_keeps_data_intact(): void
    {
        $invoice = $this->makePaidInvoice();

        $this->expectException(CannotDeletePaidInvoiceException::class);

        try {
            $invoice->delete();
        } finally {
            $this->assertNotSoftDeleted($invoice);
            $this->assertDatabaseHas('minihouse_invoice_payments', ['invoice_id' => $invoice->id]);
            $this->assertDatabaseHas('minihouse_transactions', ['invoice_payment_id' => $invoice->payments()->first()->id]);
        }
    }

    public function test_unpaid_invoice_can_still_be_deleted_normally(): void
    {
        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'B', 'address' => 'a']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'R-' . uniqid(), 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'T', 'phone' => '09' . random_int(10000000, 99999999)]);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
        $invoice = Invoice::create([
            'contract_id' => $contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'status' => Invoice::STATUS_UNPAID,
        ]);

        $invoice->delete();

        $this->assertSoftDeleted($invoice);
    }

    public function test_admin_api_returns_422_instead_of_500_when_deleting_paid_invoice(): void
    {
        $invoice = $this->makePaidInvoice();
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson("/api/admin/minihouse/invoices/{$invoice->id}");

        $response->assertStatus(422);
        $this->assertNotSoftDeleted($invoice);
    }
}

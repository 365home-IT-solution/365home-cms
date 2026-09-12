<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Audit phát hiện: chặn xoá HOÁ ĐƠN đã có thanh toán duyệt (xem InvoiceDeletionGuardTest) không phủ
// tới việc xoá RIÊNG 1 dòng InvoicePayment đã duyệt qua API .../payments/{id} — xoá thẳng ở đó vẫn
// xoá THẬT (không SoftDeletes) kéo theo cascade xoá luôn dòng "Thu" liên kết, cùng hậu quả mất dữ
// liệu sổ Thu Chi mà InvoiceObserver::deleting() được viết ra để ngăn. Test này khoá lại hành vi sau
// khi thêm chặn tương ứng ở InvoicePaymentController::destroy().
class InvoicePaymentDeletionGuardTest extends TestCase
{
    use DatabaseTransactions;

    private function makeInvoiceWithPayment(string $status): array
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
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_CASH, 'status' => $status,
            'approved_at' => $status === InvoicePayment::STATUS_APPROVED ? now() : null,
        ]);

        return [$invoice, $payment];
    }

    public function test_deleting_an_approved_payment_via_api_is_blocked(): void
    {
        [$invoice, $payment] = $this->makeInvoiceWithPayment(InvoicePayment::STATUS_APPROVED);
        $admin = User::role('super_admin')->first();
        $this->assertNotNull($admin);
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson("/api/admin/minihouse/invoices/{$invoice->id}/payments/{$payment->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('minihouse_invoice_payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('minihouse_transactions', ['invoice_payment_id' => $payment->id]);
    }

    public function test_deleting_a_pending_payment_via_api_still_works(): void
    {
        [$invoice, $payment] = $this->makeInvoiceWithPayment(InvoicePayment::STATUS_PENDING);
        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->deleteJson("/api/admin/minihouse/invoices/{$invoice->id}/payments/{$payment->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('minihouse_invoice_payments', ['id' => $payment->id]);
    }

    public function test_editing_invoice_amount_recalculates_status_after_it_was_fully_paid(): void
    {
        [$invoice] = $this->makeInvoiceWithPayment(InvoicePayment::STATUS_APPROVED);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);

        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        // Sửa lại tiền nước làm total_amount TĂNG vượt quá amount_paid cũ (3.000.000đ) — invoice phải
        // tự chuyển về "partial", không được tiếp tục hiện "paid" trong khi thực tế còn thiếu tiền.
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->putJson("/api/admin/minihouse/invoices/{$invoice->id}", [
                'water_start' => 0,
                'water_end' => 10,
                'water_unit_price' => 50000,
            ]);

        $response->assertOk();
        $invoice->refresh();
        $this->assertSame(3500000.0, $invoice->total_amount);
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
    }
}

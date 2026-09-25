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
use Modules\Minihouse\App\Services\InvoiceContentRenderer;
use Modules\Minihouse\App\Services\InvoicePaymentAllocationService;
use Modules\Minihouse\App\Services\Report\FinancialReportService;
use Modules\Minihouse\App\Services\TenantPortalService;
use Tests\TestCase;

// Kiểm tra toàn bộ luồng "công nợ tháng trước" (previousDebt/totalOwed, chuỗi chuyển phòng, phân bổ
// 1 khoản gộp nợ cũ thành nhiều InvoicePayment) — feature này trước đó CHƯA có test tự động nào dù
// đã được dùng thật ở webhook thanh toán + ghi nhận tay + báo cáo công nợ.
class InvoiceDebtCarryOverTest extends TestCase
{
    use DatabaseTransactions;

    private Building $building;
    private Room $room;
    private Tenant $tenant;
    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $zone = Zone::create(['name' => 'Z' . uniqid()]);
        $this->building = Building::create([
            'zone_id' => $zone->id, 'name' => 'Debt Test Building', 'address' => 'a',
            'electric_unit_price' => 3500, 'water_unit_price' => 15000,
        ]);
        $this->room = Room::create(['building_id' => $this->building->id, 'code' => 'D-01', 'price' => 3000000, 'status' => Room::STATUS_RENTED]);
        $this->tenant = Tenant::create(['fullname' => 'Debt Tenant', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $this->room->id]);
        $this->contract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenant->id,
            'start_date' => now()->subMonths(3), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    private function makeInvoice(string $month, float $total, string $status, float $paid = 0): Invoice
    {
        return Invoice::create([
            'contract_id' => $this->contract->id, 'month' => $month,
            'room_price' => $total, 'total_amount' => $total, 'amount_paid' => $paid, 'status' => $status,
        ]);
    }

    // ── previousDebt() / totalOwed() ────────────────────────────────────────

    public function test_previous_debt_is_zero_when_no_older_unpaid_invoices(): void
    {
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $this->assertEquals(0, InvoiceContentRenderer::previousDebt($current));
        $this->assertEquals(3000000, InvoiceContentRenderer::totalOwed($current));
    }

    public function test_previous_debt_sums_unpaid_and_partial_older_invoices(): void
    {
        $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID, 0);
        $this->makeInvoice(now()->subMonths(1)->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_PARTIAL, 1000000);
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        // Tháng -2: nợ hết 3.000.000. Tháng -1: partial, còn nợ 2.000.000. Tổng nợ cũ = 5.000.000.
        $this->assertEquals(5000000, InvoiceContentRenderer::previousDebt($current));
        $this->assertEquals(8000000, InvoiceContentRenderer::totalOwed($current));
    }

    public function test_previous_debt_ignores_paid_older_invoices(): void
    {
        $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_PAID, 3000000);
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $this->assertEquals(0, InvoiceContentRenderer::previousDebt($current));
    }

    public function test_previous_debt_ignores_future_month_invoices(): void
    {
        // Hoá đơn THÁNG SAU chưa lập (chưa tồn tại) không được tính là nợ "trước" của hoá đơn hiện tại.
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);
        Invoice::create(['contract_id' => $this->contract->id, 'month' => now()->addMonth()->startOfMonth()->toDateString(), 'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID]);

        $this->assertEquals(0, InvoiceContentRenderer::previousDebt($current));
    }

    public function test_previous_debt_ignores_the_invoice_itself(): void
    {
        // Chính hoá đơn đang xét không được tự tính nợ của chính nó (dù cùng tháng, unpaid).
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $this->assertEquals(0, InvoiceContentRenderer::previousDebt($current));
        $this->assertEquals(3000000, InvoiceContentRenderer::totalOwed($current));
    }

    public function test_previous_debt_ignores_invoices_from_other_contracts(): void
    {
        $otherZone = Zone::create(['name' => 'Z2' . uniqid()]);
        $otherBuilding = Building::create(['zone_id' => $otherZone->id, 'name' => 'Other', 'address' => 'b', 'electric_unit_price' => 3500, 'water_unit_price' => 15000]);
        $otherRoom = Room::create(['building_id' => $otherBuilding->id, 'code' => 'O-01', 'price' => 2000000, 'status' => Room::STATUS_RENTED]);
        $otherTenant = Tenant::create(['fullname' => 'Other', 'phone' => '09' . random_int(10000000, 99999999), 'room_id' => $otherRoom->id]);
        $otherContract = Contract::create(['room_id' => $otherRoom->id, 'tenant_id' => $otherTenant->id, 'start_date' => now()->subMonths(2), 'monthly_price' => 2000000, 'deposit_amount' => 2000000, 'status' => Contract::STATUS_ACTIVE]);
        Invoice::create(['contract_id' => $otherContract->id, 'month' => now()->subMonth()->startOfMonth()->toDateString(), 'room_price' => 2000000, 'total_amount' => 2000000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID]);

        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $this->assertEquals(0, InvoiceContentRenderer::previousDebt($current));
    }

    // ── contractIdChain() — chuyển phòng ─────────────────────────────────────

    public function test_contract_id_chain_is_single_id_without_transfer(): void
    {
        $invoice = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $this->assertEquals([$this->contract->id], InvoiceContentRenderer::contractIdChain($invoice));
    }

    public function test_previous_debt_follows_room_transfer_chain(): void
    {
        // Hợp đồng CŨ còn nợ 1 tháng trước khi chuyển phòng.
        $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $newContract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3200000, 'deposit_amount' => 3200000,
            'status' => Contract::STATUS_ACTIVE,
            'transferred_from_contract_id' => $this->contract->id,
        ]);

        $currentOnNewContract = Invoice::create([
            'contract_id' => $newContract->id, 'month' => now()->startOfMonth()->toDateString(),
            'room_price' => 3200000, 'total_amount' => 3200000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID,
        ]);

        $this->assertEquals([$newContract->id, $this->contract->id], InvoiceContentRenderer::contractIdChain($currentOnNewContract));
        $this->assertEquals(3000000, InvoiceContentRenderer::previousDebt($currentOnNewContract));
        $this->assertEquals(6200000, InvoiceContentRenderer::totalOwed($currentOnNewContract));
    }

    public function test_contract_id_chain_guards_against_circular_reference(): void
    {
        // Dữ liệu lỗi giả định: A trỏ ngược về B và B trỏ ngược về A — không được vòng lặp vô hạn.
        $contractA = Contract::create(['room_id' => $this->room->id, 'tenant_id' => $this->tenant->id, 'start_date' => now()->subMonths(2), 'monthly_price' => 3000000, 'deposit_amount' => 3000000, 'status' => Contract::STATUS_ACTIVE]);
        $contractB = Contract::create(['room_id' => $this->room->id, 'tenant_id' => $this->tenant->id, 'start_date' => now()->subMonth(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000, 'status' => Contract::STATUS_ACTIVE, 'transferred_from_contract_id' => $contractA->id]);
        $contractA->update(['transferred_from_contract_id' => $contractB->id]);

        $invoice = Invoice::create(['contract_id' => $contractB->id, 'month' => now()->startOfMonth()->toDateString(), 'room_price' => 3000000, 'total_amount' => 3000000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID]);

        $chain = InvoiceContentRenderer::contractIdChain($invoice);
        $this->assertCount(2, $chain);
        $this->assertEqualsCanonicalizing([$contractA->id, $contractB->id], $chain);
    }

    // ── InvoicePaymentAllocationService::allocate() ──────────────────────────

    public function test_allocate_pays_off_older_debt_first_then_current_invoice(): void
    {
        $old1 = $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID);
        $old2 = $this->makeInvoice(now()->subMonth()->startOfMonth()->toDateString(), 1000000, Invoice::STATUS_UNPAID);
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $result = InvoicePaymentAllocationService::allocate($current, 6000000, InvoicePayment::METHOD_TRANSFER, 'Test allocate');

        $this->assertCount(3, $result['payments']);
        $this->assertEquals(0, $result['unallocated']);

        $this->assertEquals(Invoice::STATUS_PAID, $old1->fresh()->status);
        $this->assertEquals(Invoice::STATUS_PAID, $old2->fresh()->status);
        $this->assertEquals(Invoice::STATUS_PAID, $current->fresh()->status);

        // FIFO — hoá đơn CŨ NHẤT (tháng -2) phải được trả trước, kiểm tra qua thứ tự payments tạo ra.
        $this->assertEquals($old1->id, $result['payments'][0]->invoice_id);
        $this->assertEquals($old2->id, $result['payments'][1]->invoice_id);
        $this->assertEquals($current->id, $result['payments'][2]->invoice_id);
    }

    public function test_allocate_defaults_to_approved_status_for_online_payment(): void
    {
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $result = InvoicePaymentAllocationService::allocate($current, 3000000, InvoicePayment::METHOD_TRANSFER, 'PayOS test');

        $this->assertEquals(InvoicePayment::STATUS_APPROVED, $result['payments'][0]->status);
        $this->assertNotNull($result['payments'][0]->approved_at);
    }

    public function test_allocate_uses_pending_status_for_manual_entry(): void
    {
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $result = InvoicePaymentAllocationService::allocate(
            $current, 3000000, InvoicePayment::METHOD_CASH, 'Manual test',
            InvoicePayment::STATUS_PENDING,
        );

        $this->assertEquals(InvoicePayment::STATUS_PENDING, $result['payments'][0]->status);
        $this->assertNull($result['payments'][0]->approved_at);
        // Chưa duyệt — Invoice status vẫn PHẢI là UNPAID (chưa cộng vào amount_paid).
        $this->assertEquals(Invoice::STATUS_UNPAID, $current->fresh()->status);
    }

    public function test_allocate_skips_partial_old_invoice_and_reports_unallocated(): void
    {
        // Hoá đơn cũ đang "1 phần" (dữ liệu ngoài luồng chuẩn) — không được tự trả thêm.
        $partial = $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_PARTIAL, 500000);
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        // Khách chuyển đúng totalOwed() = 1.500.000 (nợ còn của partial) + 3.000.000 = 4.500.000,
        // nhưng vì partial bị BỎ QUA, toàn bộ 4.500.000 sẽ dư ra sau khi trả xong hoá đơn hiện tại.
        $result = InvoicePaymentAllocationService::allocate($current, 4500000, InvoicePayment::METHOD_TRANSFER, 'Test skip partial');

        $this->assertCount(1, $result['payments']);
        $this->assertEquals($current->id, $result['payments'][0]->invoice_id);
        $this->assertEquals(1500000, $result['unallocated']);
        $this->assertEquals(Invoice::STATUS_PARTIAL, $partial->fresh()->status); // không đổi
        $this->assertEquals(500000, $partial->fresh()->amount_paid); // không đổi
    }

    public function test_allocate_stops_when_amount_insufficient_for_next_debt_invoice(): void
    {
        $old1 = $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID);
        $old2 = $this->makeInvoice(now()->subMonth()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        // Đủ trả old1 (2tr) nhưng KHÔNG đủ trả tiếp old2 (3tr) — phải dừng lại, không trả 1 phần.
        $result = InvoicePaymentAllocationService::allocate($current, 2500000, InvoicePayment::METHOD_TRANSFER, 'Insufficient test');

        $this->assertCount(1, $result['payments']);
        $this->assertEquals($old1->id, $result['payments'][0]->invoice_id);
        $this->assertEquals(Invoice::STATUS_PAID, $old1->fresh()->status);
        $this->assertEquals(Invoice::STATUS_UNPAID, $old2->fresh()->status);
        $this->assertEquals(Invoice::STATUS_UNPAID, $current->fresh()->status);
        $this->assertEquals(500000, $result['unallocated']);
    }

    public function test_allocate_does_not_duplicate_when_invoice_already_has_payment(): void
    {
        $old1 = $this->makeInvoice(now()->subMonth()->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID);
        $old1->payments()->create(['amount' => 2000000, 'paid_at' => now(), 'payment_method' => InvoicePayment::METHOD_CASH, 'status' => InvoicePayment::STATUS_APPROVED]);
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        // old1 vừa được thanh toán qua kênh khác NGAY TRƯỚC khi allocate() chạy (status vẫn cache cũ
        // UNPAID trong DB do observer resync đồng bộ ngay, nhưng giả lập race) — allocate() phải tự
        // loại nó ra vì đã có payment, KHÔNG được tạo thêm 1 khoản trùng.
        $result = InvoicePaymentAllocationService::allocate($current, 3000000, InvoicePayment::METHOD_TRANSFER, 'No duplicate test');

        $this->assertCount(1, $result['payments']);
        $this->assertEquals($current->id, $result['payments'][0]->invoice_id);
        $this->assertEquals(1, $old1->payments()->count());
    }

    public function test_allocate_follows_room_transfer_chain_for_debt_invoices(): void
    {
        $oldContractDebt = $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID);

        $newContract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3200000, 'deposit_amount' => 3200000,
            'status' => Contract::STATUS_ACTIVE, 'transferred_from_contract_id' => $this->contract->id,
        ]);
        $current = Invoice::create([
            'contract_id' => $newContract->id, 'month' => now()->startOfMonth()->toDateString(),
            'room_price' => 3200000, 'total_amount' => 3200000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID,
        ]);

        $result = InvoicePaymentAllocationService::allocate($current, 5200000, InvoicePayment::METHOD_TRANSFER, 'Chain debt test');

        $this->assertCount(2, $result['payments']);
        $this->assertEquals($oldContractDebt->id, $result['payments'][0]->invoice_id);
        $this->assertEquals($current->id, $result['payments'][1]->invoice_id);
        $this->assertEquals(Invoice::STATUS_PAID, $oldContractDebt->fresh()->status);
    }

    // ── InvoicePaymentController::store() — routing giữa allocate() và luồng cũ ──

    public function test_store_routes_to_allocation_service_when_amount_equals_total_owed(): void
    {
        $this->makeInvoice(now()->subMonth()->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID);
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/admin/minihouse/invoices/{$current->id}/payments", [
            'amount' => 5000000, 'paid_at' => now()->toDateString(), 'payment_method' => InvoicePayment::METHOD_TRANSFER,
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data');
    }

    public function test_store_uses_single_invoice_flow_when_no_previous_debt(): void
    {
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        $response = $this->withToken($token)->postJson("/api/admin/minihouse/invoices/{$current->id}/payments", [
            'amount' => 3000000, 'paid_at' => now()->toDateString(), 'payment_method' => InvoicePayment::METHOD_TRANSFER,
        ]);

        $response->assertCreated();
        // Luồng cũ trả về 1 object "data" (không phải mảng) — cấu trúc response khác nhánh allocate().
        $response->assertJsonPath('data.invoice_id', $current->id);
    }

    public function test_store_rejects_partial_amount_not_matching_total_owed_or_total_amount(): void
    {
        $this->makeInvoice(now()->subMonth()->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID);
        $current = $this->makeInvoice(now()->startOfMonth()->toDateString(), 3000000, Invoice::STATUS_UNPAID);

        $admin = User::role('super_admin')->first();
        $token = $admin->createToken('t')->plainTextToken;

        // Không khớp totalOwed() (5tr) cũng không khớp total_amount riêng hoá đơn (3tr).
        $response = $this->withToken($token)->postJson("/api/admin/minihouse/invoices/{$current->id}/payments", [
            'amount' => 4000000, 'paid_at' => now()->toDateString(), 'payment_method' => InvoicePayment::METHOD_TRANSFER,
        ]);

        $response->assertStatus(422);
    }

    // ── Báo cáo công nợ — gộp đúng theo GỐC chuỗi chuyển phòng ───────────────

    public function test_financial_report_tenant_debts_groups_transferred_contracts_together(): void
    {
        $oldDebt = $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID);

        $newContract = Contract::create([
            'room_id' => $this->room->id, 'tenant_id' => $this->tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3200000, 'deposit_amount' => 3200000,
            'status' => Contract::STATUS_ACTIVE, 'transferred_from_contract_id' => $this->contract->id,
        ]);
        $newDebt = Invoice::create([
            'contract_id' => $newContract->id, 'month' => now()->startOfMonth()->toDateString(),
            'room_price' => 3200000, 'total_amount' => 3200000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID,
        ]);

        $report = FinancialReportService::tenantDebts([$this->building->id]);

        // Cả 2 khoản nợ (hợp đồng cũ + hợp đồng mới sau chuyển phòng) phải gộp vào ĐÚNG 1 dòng —
        // trước fix này bị tách thành 2 dòng riêng vì group theo contract_id thẳng, không theo chuỗi.
        $rows = collect($report)->where('room', 'D-01');
        $this->assertCount(1, $rows, 'Debt from before and after a room transfer must merge into a single report row.');

        $row = $rows->first();
        $this->assertEquals($newContract->id, $row['contract_id']); // hiển thị hợp đồng MỚI NHẤT
        $this->assertEquals(2, $row['invoice_count']);
        $this->assertEquals($oldDebt->remainingAmount() + $newDebt->remainingAmount(), $row['total_debt']);
    }

    // ── Dashboard Portal — unpaid_total phải khớp phạm vi với unpaid_invoice_count ──

    public function test_portal_dashboard_unpaid_total_includes_debt_from_transferred_out_contract(): void
    {
        // Hợp đồng CŨ (đã chuyển phòng, không còn active) vẫn còn 1 hoá đơn chưa trả.
        $oldDebt = $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID);
        // Đánh dấu cần đủ điện/nước để lọt qua whereNotNull(electric_end, water_end) của service.
        $oldDebt->update(['electric_end' => 100, 'water_end' => 10]);

        $newRoom = Room::create(['building_id' => $this->building->id, 'code' => 'D-NEW', 'price' => 3200000, 'status' => Room::STATUS_RENTED]);
        $newContract = Contract::create([
            'room_id' => $newRoom->id, 'tenant_id' => $this->tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3200000, 'deposit_amount' => 3200000,
            'status' => Contract::STATUS_ACTIVE, 'transferred_from_contract_id' => $this->contract->id,
        ]);
        $currentInvoice = Invoice::create([
            'contract_id' => $newContract->id, 'month' => now()->startOfMonth()->toDateString(),
            'room_price' => 3200000, 'total_amount' => 3200000, 'amount_paid' => 0, 'status' => Invoice::STATUS_UNPAID,
            'electric_end' => 100, 'water_end' => 10,
        ]);

        $contracts = TenantPortalService::tenantContracts($this->tenant);

        // Cả 2 hợp đồng (cũ đã chuyển đi + mới đang active) đều PHẢI được tính vào — trước fix chỉ
        // tính đúng $activeContract (2.000.000đ của hợp đồng cũ bị thiếu khỏi tổng), trong khi
        // unpaidInvoiceCount() đã luôn đếm đủ cả 2 hoá đơn — 2 con số hiển thị cạnh nhau lệch nhau.
        $total = TenantPortalService::unpaidTotalForContracts($contracts);
        $count = TenantPortalService::unpaidInvoiceCount($contracts);

        $this->assertEquals(5200000, $total);
        $this->assertEquals(2, $count);
    }

    public function test_portal_dashboard_api_reflects_full_debt_after_room_transfer(): void
    {
        $this->makeInvoice(now()->subMonths(2)->startOfMonth()->toDateString(), 2000000, Invoice::STATUS_UNPAID)
            ->update(['electric_end' => 100, 'water_end' => 10]);

        $newRoom = Room::create(['building_id' => $this->building->id, 'code' => 'D-NEW2', 'price' => 3200000, 'status' => Room::STATUS_RENTED]);
        Contract::create([
            'room_id' => $newRoom->id, 'tenant_id' => $this->tenant->id,
            'start_date' => now()->subMonth(), 'monthly_price' => 3200000, 'deposit_amount' => 3200000,
            'status' => Contract::STATUS_ACTIVE, 'transferred_from_contract_id' => $this->contract->id,
        ]);

        $token = $this->tenant->createToken('t')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/minihouse/portal/dashboard');

        $response->assertOk();
        $this->assertEquals(2000000, $response->json('data.unpaid_total'));
        $this->assertEquals(1, $response->json('data.unpaid_invoice_count'));
    }
}

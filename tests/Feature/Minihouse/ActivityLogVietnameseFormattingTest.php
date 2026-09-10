<?php

namespace Tests\Feature\Minihouse;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Minihouse\App\Models\ActivityLog;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Models\Zone;
use Tests\TestCase;

// Người dùng báo trang "Lịch sử thao tác" (Activity Log) lúc hiện tiếng Anh lúc tiếng Việt, và hiện
// thẳng ID/UUID (VD approved_by) thay vì tên người — xem Modules\Minihouse\App\Support\
// ActivityLogFormatter (nguồn dịch DUY NHẤT) + Modules\Minihouse\Resources\views\filament\resources\
// activity-log\details.blade.php (nơi gọi formatter). Test render thẳng view này cho vài loại subject
// khác nhau để đảm bảo không còn field/giá trị tiếng Anh thô hay ID thô lọt ra ngoài.
class ActivityLogVietnameseFormattingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_invoice_payment_activity_log_renders_vietnamese_status_and_resolved_approver_name(): void
    {
        $approver = User::role('super_admin')->first();
        $this->assertNotNull($approver);
        $approver->update(['fullname' => 'Nguyễn Văn Duyệt']);
        $zone = Zone::create(['name' => 'Khu A']);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toà A', 'address' => 'Địa chỉ']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'P101', 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'Khách A', 'phone' => '0900000001']);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now(), 'monthly_price' => 3000000, 'deposit_amount' => 3000000,
            'status' => Contract::STATUS_ACTIVE,
        ]);
        $invoice = Invoice::create([
            'contract_id' => $contract->id, 'month' => now()->startOfMonth(),
            'room_price' => 3000000, 'total_amount' => 3000000, 'status' => Invoice::STATUS_UNPAID,
        ]);
        $payment = InvoicePayment::create([
            'invoice_id' => $invoice->id, 'amount' => 3000000, 'paid_at' => now(),
            'payment_method' => InvoicePayment::METHOD_CASH, 'status' => InvoicePayment::STATUS_PENDING,
        ]);

        $log = ActivityLog::create([
            'action' => ActivityLog::ACTION_UPDATED,
            'subject_type' => InvoicePayment::class,
            'subject_id' => $payment->id,
            'subject_label' => 'Thanh toán #' . $payment->id,
            'old_values' => ['status' => InvoicePayment::STATUS_PENDING, 'approved_by' => null],
            'new_values' => ['status' => InvoicePayment::STATUS_APPROVED, 'approved_by' => $approver->id],
        ]);

        $html = view('minihouse::filament.resources.activity-log.details', ['record' => $log])->render();

        $this->assertStringContainsString('Trạng thái', $html);
        $this->assertStringContainsString('Người duyệt', $html);
        $this->assertStringContainsString('Chờ duyệt', $html);
        $this->assertStringContainsString('Đã duyệt', $html);
        $this->assertStringContainsString('Nguyễn Văn Duyệt', $html);
        $this->assertStringNotContainsString((string) $approver->id, $html);
        $this->assertStringNotContainsString('pending', $html);
        $this->assertStringNotContainsString('approved_by', $html);
    }

    public function test_room_activity_log_renders_vietnamese_status_values(): void
    {
        $zone = Zone::create(['name' => 'Khu B']);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toà B', 'address' => 'Địa chỉ']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'P202', 'status' => Room::STATUS_EMPTY]);

        $log = ActivityLog::create([
            'action' => ActivityLog::ACTION_UPDATED,
            'subject_type' => Room::class,
            'subject_id' => $room->id,
            'subject_label' => $room->code,
            'old_values' => ['status' => Room::STATUS_EMPTY],
            'new_values' => ['status' => Room::STATUS_RENTED],
        ]);

        $html = view('minihouse::filament.resources.activity-log.details', ['record' => $log])->render();

        $this->assertStringContainsString('Trống', $html);
        $this->assertStringContainsString('Đang thuê', $html);
        $this->assertStringNotContainsString('dang_thue', $html);
    }

    public function test_contract_activity_log_resolves_room_and_tenant_names_instead_of_ids(): void
    {
        $zone = Zone::create(['name' => 'Khu C']);
        $building = Building::create(['zone_id' => $zone->id, 'name' => 'Toà C', 'address' => 'Địa chỉ']);
        $room = Room::create(['building_id' => $building->id, 'code' => 'P303', 'status' => Room::STATUS_RENTED]);
        $tenant = Tenant::create(['fullname' => 'Trần Thị Khách', 'phone' => '0900000002']);
        $contract = Contract::create([
            'room_id' => $room->id, 'tenant_id' => $tenant->id,
            'start_date' => now(), 'monthly_price' => 2500000, 'deposit_amount' => 2500000,
            'status' => Contract::STATUS_ACTIVE,
        ]);

        $log = ActivityLog::create([
            'action' => ActivityLog::ACTION_CREATED,
            'subject_type' => Contract::class,
            'subject_id' => $contract->id,
            'subject_label' => 'Hợp đồng #' . $contract->id,
            'old_values' => [],
            'new_values' => ['room_id' => $room->id, 'tenant_id' => $tenant->id, 'status' => Contract::STATUS_ACTIVE],
        ]);

        $html = view('minihouse::filament.resources.activity-log.details', ['record' => $log])->render();

        $this->assertStringContainsString('P303', $html);
        $this->assertStringContainsString('Trần Thị Khách', $html);
        $this->assertStringContainsString('Đang hiệu lực', $html);
        $this->assertStringNotContainsString((string) $room->id . '</td>', $html);
    }

    public function test_activity_log_table_shows_vietnamese_subject_type_instead_of_class_basename(): void
    {
        $log = ActivityLog::create([
            'action' => ActivityLog::ACTION_UPDATED,
            'subject_type' => InvoicePayment::class,
            'subject_id' => 1,
            'subject_label' => '#1',
            'old_values' => [],
            'new_values' => [],
        ]);

        $this->assertSame('Thanh toán hoá đơn', $log->subjectTypeLabel());
        $this->assertNotSame('InvoicePayment', $log->subjectTypeLabel());
    }
}
